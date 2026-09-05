<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * Sets a cart line to an exact number of units. Zero means "remove the line".
 */
final class ChangeCartItemQuantityCommand
{
    private readonly CartId $cartId;

    private readonly ItemId $itemId;

    private readonly Quantity $quantity;

    /**
     * @throws InvalidIdentifierException
     * @throws InvalidQuantityException   when the quantity is negative, not an integer, or above what a line holds
     */
    public function __construct(string $cartId, string $itemId, int|string $quantity)
    {
        $this->cartId = CartId::fromString($cartId);
        $this->itemId = ItemId::fromString($itemId);

        $units = new Quantity($quantity);

        if ($units->asInt() > CartItem::MAX_QUANTITY) {
            throw new InvalidQuantityException('Quantity must be lower or equal to ' . CartItem::MAX_QUANTITY);
        }

        $this->quantity = $units;
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }

    public function itemId(): ItemId
    {
        return $this->itemId;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    public function removesTheLine(): bool
    {
        return 0 === $this->quantity->asInt();
    }
}
