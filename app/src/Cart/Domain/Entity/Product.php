<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Entity;

use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

// Entity properties are not readonly: Doctrine re-hydrates them on refresh().
class Product
{
    private Quantity $quantity;

    public function __construct(
        private ProductId $id,
        private ProductCode $code,
        private Name $name,
        private Price $price,
        ?Quantity $quantity = null,
    ) {
        $this->quantity = $quantity ?? new Quantity(0);
    }

    public function id(): ProductId
    {
        return $this->id;
    }

    public function code(): ProductCode
    {
        return $this->code;
    }

    public function name(): Name
    {
        return $this->name;
    }

    public function setPrice(Price $price): void
    {
        $this->price = $price;
    }

    public function price(): Price
    {
        return $this->price;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    public function setQuantity(Quantity $quantity): void
    {
        $this->quantity = $quantity;
    }
}
