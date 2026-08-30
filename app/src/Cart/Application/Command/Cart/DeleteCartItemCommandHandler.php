<?php

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\Quantity;

class DeleteCartItemCommandHandler
{
    public function  __construct(
        private readonly CartRepository $cartRepository,
        private readonly CartItemRepository $cartItemRepository,
        private readonly ProductRepository $productRepository,
    ) {
    }

    /**
     * Removes an item from a cart and gives its reserved unit back to the
     * product.
     *
     * Adding a product takes a unit off its stock, and removing the item used
     * to drop the row without putting that unit back - so every add/remove
     * cycle destroyed one unit of inventory. A shopper filling and emptying a
     * cart, or any client retrying a request, walked stock down to zero with
     * nothing sold, and the product then reported itself out of stock for
     * everyone. Quantity::increment() had been written for this and was never
     * called from anywhere.
     *
     * @param DeleteCartItemCommand $command
     * @return void
     */
    public function __invoke(DeleteCartItemCommand $command): void
    {
        $item = $this->cartItemRepository->ofId($command->itemId());

        // Only give the unit back for an item that really is in this cart and
        // whose cart is still pending. Skipping the ownership check would let a
        // repeated or mistargeted delete mint stock out of nothing, which is
        // the same bug in the opposite direction; and once a cart is paid the
        // unit is sold rather than reserved, so it is not ours to return.
        if ($item !== null
            && $item->getCart()->id()->toString() === $command->cartId()->toString()
            && $item->getCart()->status()->toInt() === CartStatus::PENDING
        ) {
            $product = $item->getProduct();
            $product->setQuantity(Quantity::increment($product->quantity()));

            $this->productRepository->save($product);
        }

        $this->cartRepository->removeItem($command->cartId(), $command->itemId());
    }
}
