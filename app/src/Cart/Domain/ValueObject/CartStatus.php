<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\InvalidCartStatusException;

/**
 * Where a cart is in its life: PENDING -> PAID -> DELIVERED, and PENDING or
 * PAID -> CANCELED. The transitions themselves live on the Cart entity; this
 * object only knows the states.
 */
final class CartStatus implements \Stringable
{
    public const PENDING = 1;

    public const PAID = 2;

    public const DELIVERED = 3;

    public const CANCELED = 4;

    private const ALL = [self::PENDING, self::PAID, self::DELIVERED, self::CANCELED];

    private readonly int $value;

    /**
     * @throws InvalidCartStatusException
     */
    public function __construct(int $status)
    {
        if (!\in_array($status, self::ALL, true)) {
            throw new InvalidCartStatusException(\sprintf('Cart status is invalid: %d', $status));
        }

        $this->value = $status;
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    public static function paid(): self
    {
        return new self(self::PAID);
    }

    public static function delivered(): self
    {
        return new self(self::DELIVERED);
    }

    public static function canceled(): self
    {
        return new self(self::CANCELED);
    }

    public function isPending(): bool
    {
        return self::PENDING === $this->value;
    }

    public function isPaid(): bool
    {
        return self::PAID === $this->value;
    }

    public function isDelivered(): bool
    {
        return self::DELIVERED === $this->value;
    }

    public function isCanceled(): bool
    {
        return self::CANCELED === $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
