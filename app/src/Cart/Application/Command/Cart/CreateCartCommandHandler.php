<?php

namespace Siroko\Cart\Application\Command\Cart;

use Brick\Money\Exception\UnknownCurrencyException;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
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

        foreach ($command->getItems() as $item) {
            /** @var Product $product */
            $product = $this->productRepository->ofId($item['productId']);
            /** @var Quantity $quantity */
            $quantity = $item['quantity'];
            for ($i = 0; $i < $quantity->asInt(); $i++) {
                $cart->addItem(
                    new CartItem(
                        $this->cartItemRepository->nextIdentity(),
                        $product,
                    )
                );
            }

            $productQuantity = $product->quantity();
            $newQuantity = $productQuantity->asInt() - $quantity->asInt();
            $product->setQuantity(new Quantity($newQuantity));
            $this->productRepository->save($product);
        }

        $this->cartRepository->save($cart);

        return CartRead::fromModel($cart);
    }
}
