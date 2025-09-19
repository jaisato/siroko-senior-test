<?php

namespace Siroko\Cart\Domain\Entity;

use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Embeddable\PriceEmbeddable;

class Product
{
    /**
     * @var ProductId
     */
    private ProductId $id;

    /**
     * @var ProductCode
     */
    private ProductCode $code;

    /**
     * @var Name
     */
    private Name $name;

    /**
     * @var PriceEmbeddable
     */
    private PriceEmbeddable $price;

    /**
     * @var Quantity
     */
    private Quantity $quantity;

    /**
     * @param ProductId $id
     * @param ProductCode $code
     * @param Name $name
     * @param Price $price
     * @param Quantity|null $quantity
     * @throws InvalidQuantityException
     */
    public function __construct(ProductId $id, ProductCode $code, Name $name, Price $price, ?Quantity $quantity = null)
    {
        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
        $this->setPrice($price);
        $this->quantity = $quantity != null ? $quantity : new Quantity(0);
    }

    /**
     * @return ProductId
     */
    public function id(): ProductId
    {
        return $this->id;
    }

    /**
     * @return ProductCode
     */
    public function code(): ProductCode
    {
        return $this->code;
    }

    /**
     * @return Name
     */
    public function name(): Name
    {
        return $this->name;
    }

    /**
     * @param Price $price
     * @return void
     */
    public function setPrice(Price $price): void
    {
        $this->price = PriceEmbeddable::fromDomain($price);
    }

    /**
     * @return Price
     * @throws \Brick\Money\Exception\UnknownCurrencyException
     */
    public function price(): Price
    {
        return $this->price->toDomain();
    }

    /**
     * @return Quantity
     */
    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    /**
     * @param Quantity $quantity
     * @return void
     */
    public function setQuantity(Quantity $quantity): void
    {
        $this->quantity = $quantity;
    }
}
