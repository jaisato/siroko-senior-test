<?php

namespace Siroko\Cart\Domain\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;

class Cart
{
    private CartId $id;

    /**
     * @var Collection<int, CartItem> items in the cart
     */
    private Collection $items;

    private CartStatus $status;

    public function __construct(CartId $id, CartStatus $status)
    {
        $this->id = $id;
        $this->status = $status;
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

    /**
     * @return Collection<int, CartItem>|CartItem[]
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(CartItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setCart($this);
        }
    }

    public function removeItem(CartItem $item): void
    {
        if ($this->items->removeElement($item)) {
            if ($item->getCart() === $this) {
                $item->setCart($this);
            }
        }
    }

    /** @return Collection<int, CartItem> */
    public function items(): Collection
    {
        return $this->items;
    }
}
