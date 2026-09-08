<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\ItemId;

/**
 * The cart has no line with the requested identifier.
 *
 * A line that belongs to a different cart is reported the same way: from the
 * point of view of the cart named in the request, that line does not exist, and
 * saying otherwise would confirm identifiers that belong to somebody else.
 */
final class CartItemNotFoundException extends \DomainException
{
    public static function inCart(ItemId $itemId, CartId $cartId): self
    {
        return new self(\sprintf('Item %s not found in cart %s.', $itemId->toString(), $cartId->toString()));
    }
}
