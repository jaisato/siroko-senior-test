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

    /**
     * `quantity` is a signed INT and the sum happens in the database, so units
     * that do not fit cannot simply be added: saying so beats an out-of-range
     * error the client reads as a 500, and beats dropping the units in silence.
     */
    public static function wouldExceedMaximum(int $max): self
    {
        return new self(\sprintf('Stock cannot exceed %d units.', $max));
    }

    /**
     * A recount that leaves no room for the units pending carts are holding.
     *
     * Those units come back to the column when a line is removed or a cart is
     * abandoned, and there has to be somewhere for them to land: set to the
     * maximum with holds outstanding, the return was refused and the cart
     * transition rolled back with it.
     */
    public static function leavesNoRoomForHeldUnits(int $held, int $maximum): self
    {
        return new self(\sprintf(
            'Pending carts are holding %d unit(s) of this product, so the available count cannot go above %d.',
            $held,
            $maximum - $held,
        ));
    }
}
