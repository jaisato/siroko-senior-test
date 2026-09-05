<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Exception\InvalidProductCodeException;
use Siroko\Cart\Domain\ValueObject\ProductCode;

final class ProductCodeTest extends TestCase
{
    public function test_it_keeps_the_trimmed_value(): void
    {
        $code = ProductCode::fromString('  SKU-001 ');

        self::assertSame('SKU-001', $code->toString());
        self::assertSame('SKU-001', (string) $code);
    }

    public function test_the_bounds_are_inclusive_and_counted_in_characters(): void
    {
        self::assertSame('X', ProductCode::fromString('X')->toString());
        self::assertSame(50, mb_strlen(ProductCode::fromString(str_repeat('ñ', 50))->toString()));
    }

    public function test_equality(): void
    {
        self::assertTrue(ProductCode::fromString('A')->equals(ProductCode::fromString(' A ')));
        self::assertFalse(ProductCode::fromString('A')->equals(ProductCode::fromString('B')));
    }

    public function test_blank_is_rejected(): void
    {
        $this->expectException(InvalidProductCodeException::class);

        ProductCode::fromString('   ');
    }

    public function test_too_long_is_rejected_without_echoing_the_value(): void
    {
        try {
            ProductCode::fromString(str_repeat('Z', 51));
            self::fail('expected an exception');
        } catch (InvalidProductCodeException $e) {
            self::assertStringNotContainsString('ZZZZ', $e->getMessage());
            self::assertStringContainsString('between 1 and 50', $e->getMessage());
        }
    }

    public function test_from_persistence_does_not_apply_the_rules(): void
    {
        self::assertSame(' raw ', ProductCode::fromPersistence(' raw ')->toString());
    }
}
