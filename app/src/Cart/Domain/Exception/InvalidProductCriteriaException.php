<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

/**
 * A product listing was asked for with a filter or an order it does not offer.
 */
final class InvalidProductCriteriaException extends \DomainException
{
    public static function textTooLong(int $max): self
    {
        return new self(\sprintf('The search text must be at most %d characters long.', $max));
    }

    public static function malformedAmount(string $parameter): self
    {
        return new self(\sprintf('The query parameter "%s" must be a non-negative amount with at most four decimals.', $parameter));
    }

    public static function invertedPriceRange(): self
    {
        return new self('The query parameter "minPrice" cannot be greater than "maxPrice".');
    }

    /**
     * @param list<string> $allowed
     */
    public static function unknownSort(array $allowed): self
    {
        return new self(\sprintf('The query parameter "sort" must be one of: %s.', implode(', ', $allowed)));
    }

    public static function notABoolean(string $parameter): self
    {
        return new self(\sprintf('The query parameter "%s" must be true or false.', $parameter));
    }
}
