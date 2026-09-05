<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

/**
 * A price the domain will not accept.
 *
 * brick/money reports an unknown currency, a non-numeric amount or an amount
 * with more decimals than the currency allows through its own exception types.
 * Those are library details; the API maps this single domain exception to a
 * 400 instead of answering 500 with a stack trace behind it.
 */
final class InvalidPriceException extends \DomainException
{
    public static function unknownCurrency(): self
    {
        return new self('The price currency is not a known ISO 4217 code.');
    }

    public static function malformedAmount(): self
    {
        return new self('The price amount is not a valid decimal number.');
    }

    public static function tooManyDecimals(): self
    {
        return new self('The price amount has more decimals than the currency allows.');
    }

    public static function negative(): self
    {
        return new self('The price amount cannot be negative.');
    }
}
