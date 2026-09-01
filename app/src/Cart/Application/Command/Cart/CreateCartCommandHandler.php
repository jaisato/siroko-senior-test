<?php

namespace Siroko\Cart\Application\Command\Cart;

use Brick\Money\Exception\UnknownCurrencyException;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Exception\OutOfStockException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Siroko\Cart\Infrastructure\Api\Dto\Cart\CartRead;

class CreateCartCommandHandler
{
    /**
     * @param CartRepository $cartRepository
     * @param CartItemRepository $cartItemRepository
     * @param ProductRepository $productRepository
     */
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly CartItemRepository $cartItemRepository,
        private readonly ProductRepository $productRepository,
        private readonly TransactionalSession $session,
    ) {
    }

    /**
     * @param CreateCartCommand $command
     * @return CartRead
     * @throws UnknownCurrencyException
     */
    public function __invoke(CreateCartCommand $command): CartRead
    {
        $cart = new Cart(
            $this->cartRepository->nextIdentity(),
            new CartStatus(CartStatus::PENDING),
        );

        // Igual que en AddCartProductCommandHandler: la reserva es un ajuste
        // relativo y condicional, no un `setQuantity()` con un valor absoluto
        // sacado de la lectura de arriba. Un valor absoluto borra cualquier
        // devolución de stock que confirme otra petición entre la lectura y la
        // escritura, y comprobar el stock aparte de restarlo deja que dos
        // peticiones simultáneas pasen las dos la comprobación.
        //
        // Todo va en una transacción: reservar varios productos y guardar el
        // carrito son una sola operación, y si falla a medias no puede quedar
        // stock reservado sin carrito que lo justifique.
        $this->session->executeAtomically(function () use ($cart, $command): void {
            foreach ($command->getItems() as $item) {
                /** @var Product $product */
                $product = $this->productRepository->ofId($item['productId']);
                /** @var Quantity $quantity */
                $quantity = $item['quantity'];

                if (!$this->productRepository->reserveStock($product->id(), $quantity->asInt())) {
                    throw new OutOfStockException(
                        sprintf('Product %s does not have %d units available', $product->id()->toString(), $quantity->asInt())
                    );
                }

                for ($i = 0; $i < $quantity->asInt(); $i++) {
                    $cart->addItem(
                        new CartItem(
                            $this->cartItemRepository->nextIdentity(),
                            $product,
                        )
                    );
                }
            }

            $this->cartRepository->save($cart);
        });

        return CartRead::fromModel($cart);
    }
}
