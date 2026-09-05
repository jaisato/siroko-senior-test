<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\ItemId;

final class DeleteCartItemCommand
{
    private readonly CartId $cartId;

    private readonly ItemId $itemId;

    /**
     * @throws InvalidIdentifierException
     */
    public function __construct(string $cartId, string $itemId)
    {
        $this->cartId = CartId::fromString($cartId);
        $this->itemId = ItemId::fromString($itemId);
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }

    public function itemId(): ItemId
    {
        return $this->itemId;
    }
}
