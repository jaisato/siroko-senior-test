<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\NameInvalidLengthException;

final class Name implements StringValueObject
{
    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 200;

    private function __construct(private readonly string $value) {}

    /**
     * Length is counted in characters, not bytes: `strlen()` rejected "€10" as
     * too short while letting a 120-character multibyte name through, and the
     * message no longer echoes the value the caller already has.
     *
     * @throws NameInvalidLengthException
     */
    public static function fromString(string $value): self
    {
        $length = mb_strlen($value);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new NameInvalidLengthException(\sprintf('The name must be between %d and %d characters long.', self::MIN_LENGTH, self::MAX_LENGTH));
        }

        return new self($value);
    }

    /**
     * Rehydrates a value that was accepted when it was written.
     *
     * Validation belongs to the write path. Re-running it while loading a row
     * means that tightening a rule makes every existing product that no longer
     * satisfies it unreadable - a 500 on GET - instead of merely unwritable.
     */
    public static function fromPersistence(string $value): static
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
