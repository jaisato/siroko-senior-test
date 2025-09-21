<?php

namespace Siroko\Cart\Domain\Repository;

use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\ItemId;

interface CartRepository
{
    /**
     * @return CartId
     */
    public function nextIdentity(): CartId;

    /**
     * @param Cart $cart
     * @return void
     */
    public function save(Cart $cart): void;

    /**
     * @param CartId $id
     * @return Cart|null
     */
    public function ofId(CartId $id): ?Cart;

    /**
     * Elimina un item del cart dado su itemId
     *
     * @param CartId $cartId
     * @param ItemId $itemId
     * @return void
     */
    public function removeItem(CartId $cartId, ItemId $itemId): void;
}
