<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;

/**
 * The shape shared by the UUID-backed identifiers.
 *
 * `fromString()` used to accept any string, and the first thing to complain was
 * the Doctrine type while binding the query parameter - a 500 for a malformed
 * request. Validating here means an identifier object is a UUID by
 * construction, wherever it came from.
 *
 * The value is kept in its canonical lowercase form so that two identifiers
 * that name the same UUID compare equal.
 */
trait UuidIdentity
{
    private function __construct(private readonly string $value) {}

    /**
     * @throws InvalidIdentifierException when the value is not a UUID
     */
    public static function fromString(string $value): static
    {
        if (!Uuid::isValid($value)) {
            throw InvalidIdentifierException::forType(self::class);
        }

        return new self(Uuid::fromString($value)->toString());
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
