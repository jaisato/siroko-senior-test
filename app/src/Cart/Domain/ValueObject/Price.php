<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money as BrickMoney;
use Siroko\Cart\Domain\Exception\InvalidPriceException;
use Siroko\Cart\Domain\Exception\PriceIsNotSameCurrencyException;

/**
 * An amount of money in a given currency.
 *
 * The state is the pair (decimal amount, ISO currency code) rather than a Money
 * instance, so the object can be mapped as a Doctrine embeddable straight from
 * the XML mapping, without the Infrastructure copy (`PriceEmbeddable`) the
 * entity used to import into the domain. Money is built on demand.
 */
final class Price implements \Stringable, \JsonSerializable
{
    /** Decimal string, e.g. "19.99". Hydrated rows carry the column scale ("19.9900"). */
    private string $amount;

    /** ISO 4217 code, e.g. "EUR". */
    private string $currency;

    private function __construct(BrickMoney $money)
    {
        $this->amount = (string) $money->getAmount();
        $this->currency = $money->getCurrency()->getCurrencyCode();
    }

    /**
     * Builds a price from an amount in major units ("19.99").
     *
     * The amount has to fit the currency exactly: "19.999" in EUR used to be
     * rounded half-up in silence, so the client was charged a price it never
     * sent. A negative amount is not a price. Unknown currencies and
     * non-numeric amounts surface as domain exceptions rather than as the
     * library's own, which the API cannot map.
     *
     * @throws InvalidPriceException
     */
    public static function of(string|int $amount, string|Currency $currency): self
    {
        try {
            $money = BrickMoney::of($amount, self::currencyOf($currency), roundingMode: RoundingMode::Unnecessary);
        } catch (RoundingNecessaryException) {
            throw InvalidPriceException::tooManyDecimals();
        } catch (NumberFormatException|MathException) {
            throw InvalidPriceException::malformedAmount();
        }

        if ($money->isNegative()) {
            throw InvalidPriceException::negative();
        }

        return new self($money);
    }

    /**
     * @throws InvalidPriceException
     */
    public static function ofMinor(int|string $minor, string|Currency $currency): self
    {
        try {
            $money = BrickMoney::ofMinor($minor, self::currencyOf($currency));
        } catch (RoundingNecessaryException) {
            throw InvalidPriceException::tooManyDecimals();
        } catch (NumberFormatException|MathException) {
            throw InvalidPriceException::malformedAmount();
        }

        if ($money->isNegative()) {
            throw InvalidPriceException::negative();
        }

        return new self($money);
    }

    /**
     * @throws InvalidPriceException
     */
    public static function zero(string|Currency $currency): self
    {
        return new self(BrickMoney::zero(self::currencyOf($currency)));
    }

    /** Normalised amount, e.g. "19.99" (the column scale of a hydrated row is dropped). */
    public function amount(): string
    {
        return (string) $this->toMoney()->getAmount();
    }

    public function minor(): int
    {
        return $this->toMoney()->getMinorAmount()->toInt();
    }

    public function currency(): Currency
    {
        return $this->toMoney()->getCurrency();
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->toMoney()->isEqualTo($other->toMoney());
    }

    /**
     * Locale-free, e.g. "19.99 EUR". Presenting a price for a given locale is
     * the API's job, not the domain's.
     */
    public function __toString(): string
    {
        return \sprintf('%s %s', $this->amount(), $this->currency);
    }

    /**
     * @return array{amount: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount(),
            'currency' => $this->currency,
        ];
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->toMoney()->plus($other->toMoney()));
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->toMoney()->minus($other->toMoney()));
    }

    public function multiply(string|int|float $factor, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        return new self($this->toMoney()->multipliedBy((string) $factor, $rounding));
    }

    public function divide(string|int|float $divisor, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        return new self($this->toMoney()->dividedBy((string) $divisor, $rounding));
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->toMoney()->isGreaterThan($other->toMoney());
    }

    public function toMoney(): BrickMoney
    {
        // A hydrated amount keeps the column scale ("19.9900"); it is exact at
        // the currency scale, so no rounding is ever needed here.
        return BrickMoney::of($this->amount, Currency::of($this->currency));
    }

    /**
     * @throws InvalidPriceException
     */
    private static function currencyOf(string|Currency $currency): Currency
    {
        if ($currency instanceof Currency) {
            return $currency;
        }

        try {
            return Currency::of($currency);
        } catch (UnknownCurrencyException) {
            throw InvalidPriceException::unknownCurrency();
        }
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new PriceIsNotSameCurrencyException('Prices in different currencies cannot be combined.');
        }
    }
}
