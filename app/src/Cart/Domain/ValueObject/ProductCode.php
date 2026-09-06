<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\InvalidProductCodeException;

final class ProductCode implements StringValueObject
{
    public const MIN_LENGTH = 1;

    public const MAX_LENGTH = 50;

    /**
     * Anything but a slash and the control characters.
     *
     * Length was the only rule, so `ABC/123` was a code the API happily
     * created and then could never return: `GET /v1/products/by-code/{code}`
     * matches one path segment, and a slash - percent-encoded or not, since
     * the router decodes before it routes - reads as the start of another. The
     * lookup 404s on a code the catalogue really holds, which is worse than
     * refusing the code in the first place.
     *
     * Everything else stays: spaces and accents are reachable percent-encoded
     * and real catalogues use them. Control characters are not a restriction
     * anybody feels and have no business in an identifier that ends up in URLs
     * and log lines.
     */
    private const PATTERN = '/^[^\/\x00-\x1F\x7F]{1,50}\z/u';

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

        if (1 !== preg_match(self::PATTERN, $code)) {
            throw new InvalidProductCodeException('The product code cannot contain a slash or a control character.');
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
