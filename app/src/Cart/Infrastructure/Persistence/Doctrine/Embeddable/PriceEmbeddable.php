<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Embeddable;

use Brick\Money\Currency;
use Siroko\Cart\Domain\ValueObject\Price;

final class PriceEmbeddable
{
    /**
     * @var string
     */
    private string $amount;

    /**
     * @var string
     */
    private string $currency;

    /**
     * @param string $amount
     * @param string $currency
     */
    private function __construct(string $amount, string $currency)
    {
        $this->amount   = $amount;
        $this->currency = $currency;
    }

    /**
     * @param Price $price
     * @return self
     */
    public static function fromDomain(Price $price): self
    {
        return new self($price->amount(), $price->currency()->getCurrencyCode());
    }

    /**
     * @return Price
     * @throws \Brick\Money\Exception\UnknownCurrencyException
     */
    public function toDomain(): Price
    {
        return Price::of($this->amount, Currency::of($this->currency));
    }
}
