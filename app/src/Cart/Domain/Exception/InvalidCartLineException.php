<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

/**
 * A line of a cart request that cannot be turned into an order line.
 *
 * Covers the shape of the request rather than its values: a missing key, a
 * value of the wrong type, or more lines than one cart accepts. Quantities and
 * identifiers that are present but wrong raise their own exceptions.
 */
final class InvalidCartLineException extends \DomainException
{
    public static function notAnObject(int $position): self
    {
        return new self(\sprintf('Line %d must be an object with "productId" and "quantity".', $position));
    }

    public static function missingField(int $position, string $field): self
    {
        return new self(\sprintf('Line %d is missing the field "%s".', $position, $field));
    }

    public static function wrongType(int $position, string $field, string $expected): self
    {
        return new self(\sprintf('Line %d: the field "%s" must be %s.', $position, $field, $expected));
    }

    public static function tooManyLines(int $max): self
    {
        return new self(\sprintf('A cart accepts at most %d lines.', $max));
    }
}
