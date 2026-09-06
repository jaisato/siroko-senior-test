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

    /**
     * Length was the only rule, so `ABC/123` was a code the API created and
     * then could never return: `GET /v1/products/by-code/{code}` matches one
     * path segment, and a slash - percent-encoded or not, the router decodes
     * before it routes - starts another. A 404 for a code the catalogue holds
     * is worse than refusing the code.
     */
    public function test_a_code_the_by_code_lookup_could_never_address_is_refused(): void
    {
        $this->expectException(InvalidProductCodeException::class);
        $this->expectExceptionMessage('slash or a control character');

        ProductCode::fromString('ABC/123');
    }

    public function test_a_control_character_is_refused_too(): void
    {
        $this->expectException(InvalidProductCodeException::class);

        ProductCode::fromString("K3\nBLACK");
    }

    /** Everything else stays: real catalogues use spaces and accents. */
    public function test_spaces_and_accents_are_still_codes(): void
    {
        self::assertSame('K3.BLACK v2', ProductCode::fromString('K3.BLACK v2')->toString());
        self::assertSame('ñ', ProductCode::fromString('ñ')->toString());
    }

    /** A row written before the rule is rehydrated as it stands, not re-judged. */
    public function test_a_stored_code_is_not_re_validated(): void
    {
        self::assertSame('ABC/123', ProductCode::fromPersistence('ABC/123')->toString());
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
