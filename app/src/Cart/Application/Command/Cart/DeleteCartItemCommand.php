<?php

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\ItemId;

class DeleteCartItemCommand
{
    /**
     * @var CartId
     */
    private CartId $cartId;

    /**
     * @var ItemId
     */
    private ItemId $itemId;

    /**
     * @param string $cartId
     * @param string $itemId
     */
    public function __construct(string $cartId, string $itemId)
    {
        $this->cartId = CartId::fromString($cartId);
        $this->itemId = ItemId::fromString($itemId);
    }

    /**
     * @return CartId
     */
    public function cartId(): CartId
    {
        return $this->cartId;
    }

    /**
     * @return ItemId
     */
    public function itemId(): ItemId
    {
        return $this->itemId;
    }
}
