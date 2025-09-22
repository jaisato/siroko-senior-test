<?php

namespace Siroko\Cart\Domain\Repository;

use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\ValueObject\ItemId;

interface CartItemRepository
{
    /**
     * @return ItemId
     */
    public function nextIdentity(): ItemId;

    /**
     * @param CartItem $item
     * @return void
     */
    public function save(CartItem $item): void;

    /**
     * @param ItemId $id
     * @return CartItem|null
     */
    public function ofId(ItemId $id): ?CartItem;
}
