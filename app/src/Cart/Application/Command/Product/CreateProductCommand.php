<?php

namespace Siroko\Cart\Application\Command\Product;

use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\Quantity;

class CreateProductCommand
{
    /**
     * @var ProductCode
     */
    private ProductCode $code;

    /**
     * @var Name
     */
    private Name $name;

    /**
     * @var Price
     */
    private Price $price;

    /**
     * @var Quantity
     */
    private Quantity $quantity;


    /**
     * @param string $code
     * @param string $name
     * @param string $priceAmount
     * @param string $priceCurrency
     * @param int|string $quantity
     * @throws \Siroko\Cart\Domain\Exception\InvalidProductCodeException
     * @throws \Siroko\Cart\Domain\Exception\InvalidQuantityException
     * @throws \Siroko\Cart\Domain\Exception\NameInvalidLengthException
     */
    public function __construct(
        string $code,
        string $name,
        string $priceAmount,
        string $priceCurrency,
        int|string $quantity,
    ) {
        $this->code = new ProductCode($code);
        $this->name = new Name($name);
        $this->price = Price::of($priceAmount, $priceCurrency);
        $this->quantity = new Quantity($quantity);
    }

    public function getCode(): ProductCode
    {
        return $this->code;
    }

    public function getName(): Name
    {
        return $this->name;
    }

    public function getPrice(): Price
    {
        return $this->price;
    }

    public function getQuantity(): Quantity
    {
        return $this->quantity;
    }
}
