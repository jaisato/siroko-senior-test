<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;

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

    /**
     * Only a pending cart changes. Once paid, its lines are what the customer
     * bought; adding to it reserved stock that nothing ever released, because
     * the removal path rightly refuses to give back units that were sold.
     *
     * @throws InvalidCartStatusException
     */
    public function addItem(CartItem $item): void
    {
        $this->ensurePending();

        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setCart($this);
        }
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
