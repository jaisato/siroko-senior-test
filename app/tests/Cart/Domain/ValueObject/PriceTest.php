<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\ValueObject;

use Brick\Money\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Exception\InvalidPriceException;
use Siroko\Cart\Domain\Exception\PriceIsNotSameCurrencyException;
use Siroko\Cart\Domain\ValueObject\Price;

final class PriceTest extends TestCase
{
    public function test_it_is_built_from_an_amount_in_major_units(): void
    {
        $price = Price::of('19.99', 'EUR');

        self::assertSame('19.99', $price->amount());
        self::assertSame(1999, $price->minor());
        self::assertSame('EUR', $price->currency()->getCurrencyCode());
        self::assertSame('EUR 19.99', (string) $price->toMoney());
    }

    public function test_integers_and_currency_objects_are_accepted(): void
    {
        $price = Price::of(10, Currency::of('USD'));

        self::assertSame('10.00', $price->amount());
        self::assertSame('USD', $price->currency()->getCurrencyCode());
    }

    public function test_it_is_built_from_minor_units(): void
    {
        self::assertSame('12.34', Price::ofMinor(1234, 'EUR')->amount());
        self::assertSame('0.00', Price::zero('EUR')->amount());
    }

    /**
     * The string form used to be the es_ES rendering through a method that
     * does not exist in the pinned brick/money. The domain now renders a
     * locale-free "19.99 EUR" and leaves presentation to the read models.
     */
    public function test_its_string_form_is_locale_free(): void
    {
        self::assertSame('19.99 EUR', (string) Price::of('19.99', 'EUR'));
        self::assertSame('5.00 EUR', (string) Price::of('5', 'EUR'));
    }

    public function test_it_serialises_to_amount_and_currency(): void
    {
        self::assertSame(
            '{"amount":"19.99","currency":"EUR"}',
            json_encode(Price::of('19.99', 'EUR'), \JSON_THROW_ON_ERROR),
        );
    }

    public function test_equality_needs_the_same_amount_and_currency(): void
    {
        self::assertTrue(Price::of('19.99', 'EUR')->equals(Price::of('19.990', 'EUR')));
        self::assertFalse(Price::of('19.99', 'EUR')->equals(Price::of('19.98', 'EUR')));
        self::assertFalse(Price::of('19.99', 'EUR')->equals(Price::of('19.99', 'USD')));
    }

    /** Silent half-up rounding charged the client a price it never sent. */
    public function test_an_amount_with_more_decimals_than_the_currency_is_rejected(): void
    {
        $this->expectException(InvalidPriceException::class);
        $this->expectExceptionMessage('more decimals');

        Price::of('19.999', 'EUR');
    }

    public function test_a_whole_number_in_a_zero_decimal_currency_is_fine(): void
    {
        self::assertSame('1500', Price::of('1500', 'JPY')->amount());
    }

    public function test_a_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidPriceException::class);
        $this->expectExceptionMessage('negative');

        Price::of('-0.01', 'EUR');
    }

    public function test_negative_minor_units_are_rejected(): void
    {
        $this->expectException(InvalidPriceException::class);

        Price::ofMinor(-1, 'EUR');
    }

    /** brick's own exception types are library details the API cannot map. */
    #[DataProvider('malformedAmounts')]
    public function test_a_non_numeric_amount_is_rejected(string $amount): void
    {
        $this->expectException(InvalidPriceException::class);
        $this->expectExceptionMessage('decimal number');

        Price::of($amount, 'EUR');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedAmounts(): iterable
    {
        yield 'words' => ['nineteen'];
        yield 'empty' => [''];
        yield 'currency symbol' => ['€19.99'];
        yield 'thousands separator' => ['1,999.00'];
        yield 'leading space' => [' 5'];
    }

    public function test_an_unknown_currency_is_rejected(): void
    {
        $this->expectException(InvalidPriceException::class);
        $this->expectExceptionMessage('currency');

        Price::of('19.99', 'XYZ');
    }

    public function test_arithmetic(): void
    {
        $price = Price::of('10.00', 'EUR');

        self::assertSame('15.50', $price->add(Price::of('5.50', 'EUR'))->amount());
        self::assertSame('4.50', $price->subtract(Price::of('5.50', 'EUR'))->amount());
        self::assertSame('30.00', $price->multiply(3)->amount());
        self::assertSame('3.33', $price->divide(3)->amount(), 'division rounds half-up to the currency scale');
        self::assertTrue($price->greaterThan(Price::of('9.99', 'EUR')));
        self::assertFalse($price->greaterThan(Price::of('10.00', 'EUR')));
        self::assertSame('10.00', $price->amount(), 'operations return new values');
    }

    public function test_prices_in_different_currencies_cannot_be_combined(): void
    {
        $this->expectException(PriceIsNotSameCurrencyException::class);

        Price::of('10.00', 'EUR')->add(Price::of('10.00', 'USD'));
    }

    public function test_comparing_different_currencies_is_refused_too(): void
    {
        $this->expectException(PriceIsNotSameCurrencyException::class);

        Price::of('10.00', 'EUR')->greaterThan(Price::of('1.00', 'USD'));
    }
}
