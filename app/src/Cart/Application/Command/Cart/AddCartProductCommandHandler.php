<?php

namespace Siroko\Cart\Application\Command\Cart;

use Brick\Money\Exception\UnknownCurrencyException;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\Quantity;
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

        $newQuantity = new Quantity($product->quantity()->asInt() - 1);
        $product->setQuantity($newQuantity);

        $this->productRepository->save($product);

        $cart->addItem(
            new CartItem(
                $this->cartItemRepository->nextIdentity(),
                $product
            )
        );

        $this->cartRepository->save($cart);

        return CartRead::fromModel($cart);
    }
}
