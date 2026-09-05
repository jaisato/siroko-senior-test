<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Application\Dto\Cart\CartRead;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\OutOfStockException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class AddCartProductCommandHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly ProductRepository $productRepository,
        private readonly CartItemRepository $cartItemRepository,
        private readonly TransactionalSession $session,
    ) {}

    /**
     * @throws ProductNotFoundException
     * @throws CartNotFoundException
     * @throws InvalidCartStatusException when the cart is no longer pending
     * @throws OutOfStockException
     */
    public function __invoke(AddCartProductCommand $command): CartRead
    {
        $product = $this->productRepository->ofId($command->productId());

        if (null === $product) {
            throw ProductNotFoundException::withId($command->productId());
        }

        $cart = $this->session->executeAtomically(function () use ($command, $product): Cart {
            // El carrito se bloquea antes de tocar el producto, y dentro de la
            // transacción. Sin esto el orden de cerrojos quedaba invertido
            // respecto a borrar una línea, y las dos operaciones se
            // interbloqueaban: `cart_item` tiene clave ajena NOT NULL a `cart`,
            // así que insertar la línea toma un cerrojo compartido sobre la fila
            // del carrito. Este handler tomaba entonces producto -> carrito,
            // mientras que DeleteCartItemCommandHandler toma carrito ->
            // producto. Con un alta y un borrado solapados sobre el mismo
            // carrito y producto, cada transacción esperaba a la fila que tenía
            // la otra; MySQL aborta una, y el bus de escritura no reintenta, así
            // que una petición perfectamente válida devolvía un 500.
            $cart = $this->cartRepository->ofIdForUpdate($command->cartId());

            if (null === $cart) {
                throw CartNotFoundException::withId($command->cartId());
            }

            // Checked under the row lock, before any stock moves. Adding to a
            // paid cart reserved a unit that nothing could ever release - the
            // removal path refuses to return stock for a cart that is not
            // pending, correctly, because those units were sold - so every such
            // request destroyed one unit of inventory. Same 409 as checkout.
            $cart->ensurePending();

            $this->addProduct($cart, $product);

            return $cart;
        });

        return CartRead::fromModel($cart);
    }

    /**
     * Reserva atómica. Comprobar el stock y restarlo tienen que ser la misma
     * operación: separadas, dos altas simultáneas pasan las dos la
     * comprobación y venden más unidades de las que hay. Y tiene que ser un
     * ajuste relativo, no un `setQuantity()` con un valor absoluto calculado
     * sobre una lectura previa, porque eso borra las devoluciones de stock que
     * confirme otra petición entre medias.
     *
     * Que no haya stock sigue siendo una respuesta que el cliente puede
     * entender: sin esto, la resta llegaba a `new Quantity(-1)` y se le decía
     * "Quantity must be greater or equal to 0" con un 400 -una invariante
     * interna, presentada como petición mal formada, para una petición que
     * estaba bien-.
     *
     * Se llama con el carrito ya bloqueado.
     */
    private function addProduct(Cart $cart, Product $product): void
    {
        if (!$this->productRepository->reserveStock($product->id(), 1)) {
            throw new OutOfStockException('Product is out of stock');
        }

        $cart->addItem(
            new CartItem(
                $this->cartItemRepository->nextIdentity(),
                $product,
            ),
        );

        $this->cartRepository->save($cart);
    }
}
