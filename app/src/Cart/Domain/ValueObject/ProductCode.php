<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\InvalidProductCodeException;

final class ProductCode implements StringValueObject
{
    public const MIN_LENGTH = 1;

    public const MAX_LENGTH = 50;

    private function __construct(private readonly string $value) {}

    /**
     * Surrounding whitespace is not part of a code; the length is checked in
     * characters, and the message does not echo the rejected value.
     *
     * @throws InvalidProductCodeException
     */
    public static function fromString(string $code): self
    {
        $code = trim($code);
        $length = mb_strlen($code);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidProductCodeException(\sprintf('The product code must be between %d and %d characters long.', self::MIN_LENGTH, self::MAX_LENGTH));
        }

        return new self($code);
    }

    /**
     * Rehydrates a value that was accepted when it was written; see
     * {@see Name::fromPersistence()} for why the rules are not re-applied.
     */
    public static function fromPersistence(string $code): static
    {
        return new self($code);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
