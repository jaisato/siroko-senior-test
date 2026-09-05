<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

/**
 * A stock adjustment that does not say what it wants: an absolute `quantity`
 * or a relative `delta`, exactly one of them.
 */
final class InvalidStockAdjustmentException extends \DomainException
{
    public static function exactlyOneOfQuantityOrDelta(): self
    {
        return new self('Send exactly one of "quantity" (absolute) or "delta" (relative).');
    }

    public static function deltaIsZero(): self
    {
        return new self('A delta of 0 changes nothing.');
    }

    public static function deltaOutOfRange(int $max): self
    {
        return new self(\sprintf('The delta must be an integer between -%d and %d.', $max, $max));
    }
}
