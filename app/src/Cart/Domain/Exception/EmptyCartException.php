<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

/**
 * A cart with no lines was asked to do something only a cart with lines can.
 *
 * Checking out an empty cart used to succeed: the cart became "paid" with a
 * total of nothing, and a client could not tell that payment from a real one.
 * There is nothing to pay for, so the request conflicts with the cart's state.
 */
final class EmptyCartException extends \DomainException
{
    public static function cannotBePaid(): self
    {
        return new self('Cart is empty: there is nothing to check out.');
    }
}
