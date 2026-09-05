<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\InvalidQuantityException;

final class Quantity implements \Stringable
{
    public const MIN_QUANTITY = 0;

    /**
     * Largest value a signed 32-bit INT column holds. Anything above it used to
     * be accepted here and rejected by the database with "Out of range" - a
     * 500 for a request that is simply asking for too much.
     */
    public const MAX_QUANTITY = 2147483647;

    private readonly int $quantity;

    /**
     * A string is accepted for the benefit of JSON clients that quote their
     * numbers, but it has to be an integer literal: `intval()` turned "abc"
     * into 0 and "12abc" into 12, so garbage became a valid stock level.
     *
     * @throws InvalidQuantityException
     */
    public function __construct(string|int $quantity)
    {
        if (\is_string($quantity)) {
            if (1 !== preg_match('/^-?\d{1,10}$/', $quantity)) {
                throw new InvalidQuantityException('Quantity must be an integer.');
            }

            $quantity = (int) $quantity;
        }

        if ($quantity < self::MIN_QUANTITY) {
            throw new InvalidQuantityException('Quantity must be greater or equal to ' . self::MIN_QUANTITY);
        }

        if ($quantity > self::MAX_QUANTITY) {
            throw new InvalidQuantityException('Quantity must be lower or equal to ' . self::MAX_QUANTITY);
        }

        $this->quantity = $quantity;
    }

    public function asInt(): int
    {
        return $this->quantity;
    }

    public function asString(): string
    {
        return (string) $this->quantity;
    }

    public function __toString(): string
    {
        return $this->asString();
    }

    /**
     * @throws InvalidQuantityException when the quantity is already zero
     */
    public static function decrement(self $quantity): self
    {
        return new self($quantity->asInt() - 1);
    }

    /**
     * @throws InvalidQuantityException when the quantity is already at its maximum
     */
    public static function increment(self $quantity): self
    {
        return new self($quantity->asInt() + 1);
    }
}
