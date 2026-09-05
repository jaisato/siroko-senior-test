<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;

/**
 * A UUID-backed identifier, as the persistence layer needs to see it.
 */
interface Identifier extends \Stringable
{
    /**
     * @throws InvalidIdentifierException when the value is not a UUID
     */
    public static function fromString(string $value): static;

    public function toString(): string;
}
