<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\InvalidCustomerIdException;

/**
 * Who a cart belongs to.
 *
 * Customers are not a resource of this API; the identifier is whatever the
 * authentication layer says the caller is (an API key maps to one). It is a
 * short opaque string rather than a UUID so that an external identity system
 * can supply its own identifiers as they are.
 */
final class CustomerId implements StringValueObject
{
    public const MAX_LENGTH = 64;

    /**
     * Printable, no whitespace: an identifier, not a name. Anchored with `\z`,
     * not `$`, which would let a trailing newline through.
     */
    private const PATTERN = '/^[\x21-\x7E]{1,64}\z/';

    private function __construct(private readonly string $value) {}

    /**
     * @throws InvalidCustomerIdException
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidCustomerIdException::malformed(self::MAX_LENGTH);
        }

        return new self($value);
    }

    /**
     * Rehydrates a value that was accepted when it was written; see
     * {@see Name::fromPersistence()} for why the rules are not re-applied.
     */
    public static function fromPersistence(string $value): static
    {
        return new self($value);
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
