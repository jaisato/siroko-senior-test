<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Exception\NameInvalidLengthException;
use Siroko\Cart\Domain\ValueObject\Name;

final class NameTest extends TestCase
{
    public function test_it_keeps_the_value(): void
    {
        $name = Name::fromString('Gafas Siroko');

        self::assertSame('Gafas Siroko', $name->toString());
        self::assertSame('Gafas Siroko', (string) $name);
    }

    public function test_the_bounds_are_inclusive(): void
    {
        self::assertSame(3, mb_strlen(Name::fromString('abc')->toString()));
        self::assertSame(200, mb_strlen(Name::fromString(str_repeat('a', 200))->toString()));
    }

    /**
     * Length is counted in characters. With `strlen()`, "€10" (3 characters,
     * 5 bytes) passed while a 3-character accented name such as "ñoñ" (5 bytes)
     * was measured wrong, and a 200-character multibyte name was refused.
     */
    public function test_length_is_counted_in_characters_not_bytes(): void
    {
        self::assertSame('ñoñ', Name::fromString('ñoñ')->toString());
        self::assertSame(200, mb_strlen(Name::fromString(str_repeat('ñ', 200))->toString()));
    }

    public function test_too_short_is_rejected_without_echoing_the_value(): void
    {
        try {
            Name::fromString('ab');
            self::fail('expected an exception');
        } catch (NameInvalidLengthException $e) {
            self::assertStringNotContainsString('ab', $e->getMessage());
            self::assertStringContainsString('between 3 and 200', $e->getMessage());
        }
    }

    public function test_too_long_is_rejected(): void
    {
        $this->expectException(NameInvalidLengthException::class);

        Name::fromString(str_repeat('a', 201));
    }

    public function test_from_persistence_does_not_apply_the_rules(): void
    {
        self::assertSame('x', Name::fromPersistence('x')->toString());
    }
}
