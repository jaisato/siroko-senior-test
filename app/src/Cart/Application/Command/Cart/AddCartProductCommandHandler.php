<?php

namespace Siroko\Cart\Application\Command\Cart;

use Brick\Money\Exception\UnknownCurrencyException;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Exception\OutOfStockException;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;
use Siroko\Cart\Infrastructure\Api\Dto\Cart\CartRead;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AddCartProductCommandHandler
{
    /**
     * @param CartRepository $cartRepository
     * @param ProductRepository $productRepository
     * @param CartItemRepository $cartItemRepository
     */
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly ProductRepository $productRepository,
        private readonly CartItemRepository $cartItemRepository,
        private readonly TransactionalSession $session,
    ) {
    }

    /**
     * @param AddCartProductCommand $command
     * @return CartRead
     * @throws UnknownCurrencyException
     */
    public function __invoke(AddCartProductCommand $command): CartRead
    {
        $cart = $this->cartRepository->ofId($command->cartId());

        if ($cart === null) {
            throw new NotFoundHttpException("Cart not found");
        }

        $product = $this->productRepository->ofId($command->productId());

        if ($product === null) {
            throw new NotFoundHttpException("Product not found");
        }

        // Reserva atómica. Comprobar el stock y restarlo tienen que ser la
        // misma operación: separadas, dos altas simultáneas pasan las dos la
        // comprobación y venden más unidades de las que hay. Y tiene que ser un
        // ajuste relativo, no un `setQuantity()` con un valor absoluto
        // calculado sobre la lectura de arriba, porque eso borra las
        // devoluciones de stock que confirme otra petición entre medias.
        //
        // Que no haya stock sigue siendo una respuesta que el cliente puede
        // entender: sin esto, la resta llegaba a `new Quantity(-1)` y se le
        // decía "Quantity must be greater or equal to 0" con un 400 -una
        // invariante interna, presentada como petición mal formada, para una
        // petición que estaba bien-.
        $this->session->executeAtomically(function () use ($cart, $product): void {
            if (!$this->productRepository->reserveStock($product->id(), 1)) {
                throw new OutOfStockException('Product is out of stock');
            }

            $cart->addItem(
                new CartItem(
                    $this->cartItemRepository->nextIdentity(),
                    $product
                )
            );

            $this->cartRepository->save($cart);
        });

        return CartRead::fromModel($cart);
    }
}
