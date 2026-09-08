<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

/**
 * A value object stored as a single string column.
 *
 * `fromPersistence()` rebuilds the object from a value that was accepted when
 * it was written, without re-applying the rules of the write path.
 */
interface StringValueObject extends \Stringable
{
    public static function fromPersistence(string $value): static;

    public function toString(): string;
}
