<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

class Cart
{
    /**
     * @var Collection<int, CartItem>
     */
    private Collection $items;

    public function __construct(
        private CartId $id,
        private CartStatus $status,
    ) {
        $this->items = new ArrayCollection();
    }

    public function id(): CartId
    {
        return $this->id;
    }

    public function status(): CartStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    /**
     * @return Collection<int, CartItem>
     */
    public function items(): Collection
    {
        return $this->items;
    }

    public function itemOfId(ItemId $id): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->id()->equals($id)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The line holding a product, if the cart has one. A cart holds at most
     * one line per product; that is what lets "add this product" mean "one
     * more unit" rather than "one more row".
     */
    public function itemForProduct(ProductId $productId): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->getProduct()->id()->equals($productId)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Puts units of a product in the cart: on the line that already holds the
     * product, or on a new one identified by `$newLineId`.
     *
     * Only a pending cart changes. Once paid, its lines are what the customer
     * bought; adding to it reserved stock that nothing ever released, because
     * the removal path rightly refuses to give back units that were sold.
     *
     * The caller has reserved the units already; the cart only records where
     * they went.
     *
     * @throws InvalidCartStatusException
     * @throws InvalidQuantityException   when the line would exceed CartItem::MAX_QUANTITY
     */
    public function addProduct(ItemId $newLineId, Product $product, Quantity $units): CartItem
    {
        $this->ensurePending();

        $line = $this->itemForProduct($product->id());

        if (null !== $line) {
            $line->increase($units);

            return $line;
        }

        $line = new CartItem($newLineId, $product, $units);
        $this->items->add($line);
        $line->setCart($this);

        return $line;
    }

    /**
     * Attaches a ready-made line. Adding the same instance twice is a no-op; a
     * second line for a product the cart already holds folds into the existing
     * one, so the "one line per product" rule holds however the line arrives.
     *
     * @throws InvalidCartStatusException
     * @throws InvalidQuantityException   when the merged line would exceed CartItem::MAX_QUANTITY
     */
    public function addItem(CartItem $item): void
    {
        $this->ensurePending();

        if ($this->items->contains($item)) {
            return;
        }

        $line = $this->itemForProduct($item->getProduct()->id());

        if (null !== $line) {
            $line->increase($item->quantity());

            return;
        }

        $this->items->add($item);
        $item->setCart($this);
    }

    /**
     * The inverse side is enough: the association is mapped with
     * orphan-removal, so dropping the item from this collection is what
     * deletes it.
     *
     * @throws InvalidCartStatusException
     */
    public function removeItem(CartItem $item): void
    {
        $this->ensurePending();

        $this->items->removeElement($item);
    }

    /**
     * Checking out a cart twice is a conflict, not a fresh payment.
     *
     * @throws InvalidCartStatusException
     */
    public function pay(): void
    {
        $this->ensurePending();

        $this->status = CartStatus::paid();
    }

    /**
     * @throws InvalidCartStatusException
     */
    public function ensurePending(): void
    {
        if (!$this->status->isPending()) {
            throw new InvalidCartStatusException('Cart is not pending');
        }
    }
}
