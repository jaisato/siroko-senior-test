<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\InvalidCartStatusException;

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

    public function isPending(): bool
    {
        return self::PENDING === $this->value;
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
