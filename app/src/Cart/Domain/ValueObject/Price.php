<?php

namespace Siroko\Cart\Domain\ValueObject;

use Brick\Money\Money as BrickMoney;
use Brick\Money\Currency;
use Brick\Math\RoundingMode;
use JsonSerializable;
use Siroko\Cart\Domain\Exception\PriceIsNotSameCurrencyException;
use Stringable;

final class Price implements Stringable, JsonSerializable
{
    private BrickMoney $money;

    private function __construct(BrickMoney $money)
    {
        $this->money = $money;
    }

    public static function of(
        string|int $amount,
        string|Currency $currency,
        RoundingMode $rounding = RoundingMode::HALF_UP
    ): self {
        $cur = \is_string($currency) ? Currency::of($currency) : $currency;
        // aplica la escala por defecto de la divisa (EUR=2, etc.)
        return new self(BrickMoney::of($amount, $cur, roundingMode: $rounding));
    }

    public static function ofMinor(int|string $minor, string|Currency $currency): self
    {
        $cur = \is_string($currency) ? Currency::of($currency) : $currency;
        return new self(BrickMoney::ofMinor($minor, $cur));
    }

    public static function zero(string|Currency $currency): self
    {
        $cur = \is_string($currency) ? Currency::of($currency) : $currency;
        return new self(BrickMoney::zero($cur));
    }

    public function amount(): string
    {
        return (string) $this->money->getAmount();
    }

    public function minor(): int
    {
        return $this->money->getMinorAmount()->toInt();
    }

    public function currency(): Currency
    {
        return $this->money->getCurrency();
    }

    public function equals(self $other): bool
    {
        return $this->money->isEqualTo($other->toMoney());
    }

    public function __toString(): string
    {
        return $this->money->formatTo('es_ES');
    }

    public function jsonSerialize(): array
    {
        return [
            'amount'   => $this->amount(),
            'currency' => $this->currency()->getCurrencyCode(),
        ];
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->money->plus($other->money));
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->money->minus($other->money));
    }

    public function multiply(string|int|float $factor, RoundingMode $rounding = RoundingMode::HALF_UP): self
    {
        return new self($this->money->multipliedBy((string)$factor, $rounding));
    }

    public function divide(string|int|float $divisor, RoundingMode $rounding = RoundingMode::HALF_UP): self
    {
        return new self($this->money->dividedBy((string)$divisor, $rounding));
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->money->isGreaterThan($other->money);
    }

    public function toMoney(): BrickMoney
    {
        return $this->money;
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->money->getCurrency()->is($other->toMoney()->getCurrency())) {
            throw new PriceIsNotSameCurrencyException('No se pueden operar precios con distinta divisa.');
        }
    }
}
