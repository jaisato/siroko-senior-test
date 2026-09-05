<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

/**
 * A product update whose shape makes no sense, before any value is looked at:
 * nothing to change, or half a price.
 */
final class InvalidProductUpdateException extends \DomainException
{
    public static function nothingToChange(): self
    {
        return new self('The update names no field to change: send at least one of "name", "code" or "price".');
    }

    public static function priceNeedsAmountAndCurrency(): self
    {
        return new self('A price is an object with "amount" and "currency".');
    }
}
