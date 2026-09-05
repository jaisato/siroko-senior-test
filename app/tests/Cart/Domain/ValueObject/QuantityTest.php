<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class QuantityTest extends TestCase
{
    public function test_it_holds_a_non_negative_integer(): void
    {
        $quantity = new Quantity(7);

        self::assertSame(7, $quantity->asInt());
        self::assertSame('7', $quantity->asString());
        self::assertSame('7', (string) $quantity);
        self::assertSame(0, (new Quantity(0))->asInt(), 'stock can be exhausted');
    }

    public function test_integer_strings_are_accepted(): void
    {
        self::assertSame(12, (new Quantity('12'))->asInt());
        self::assertSame(0, (new Quantity('0'))->asInt());
    }

    /** `intval()` turned "abc" into 0 and "12abc" into 12: garbage became stock. */
    #[DataProvider('garbage')]
    public function test_non_integer_strings_are_rejected(string $value): void
    {
        $this->expectException(InvalidQuantityException::class);
        $this->expectExceptionMessage('integer');

        new Quantity($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'word' => ['abc'];
        yield 'trailing garbage' => ['12abc'];
        yield 'decimal' => ['1.5'];
        yield 'empty' => [''];
        yield 'space' => [' 12'];
        yield 'plus sign' => ['+3'];
        yield 'too long to be an int column' => ['99999999999'];
    }

    public function test_negative_quantities_are_rejected(): void
    {
        $this->expectException(InvalidQuantityException::class);
        $this->expectExceptionMessage('greater or equal to 0');

        new Quantity(-1);
    }

    public function test_a_negative_string_is_rejected_as_negative(): void
    {
        $this->expectException(InvalidQuantityException::class);
        $this->expectExceptionMessage('greater or equal to 0');

        new Quantity('-3');
    }

    /** Anything above a signed INT column used to fail in the database with a 500. */
    public function test_quantities_above_the_column_range_are_rejected(): void
    {
        self::assertSame(Quantity::MAX_QUANTITY, (new Quantity(Quantity::MAX_QUANTITY))->asInt());

        $this->expectException(InvalidQuantityException::class);
        $this->expectExceptionMessage('lower or equal to');

        new Quantity(Quantity::MAX_QUANTITY + 1);
    }

    public function test_increment_and_decrement_return_new_values(): void
    {
        $three = new Quantity(3);

        self::assertSame(4, Quantity::increment($three)->asInt());
        self::assertSame(2, Quantity::decrement($three)->asInt());
        self::assertSame(3, $three->asInt());
    }

    public function test_decrementing_zero_is_refused(): void
    {
        $this->expectException(InvalidQuantityException::class);

        Quantity::decrement(new Quantity(0));
    }
}
