<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Product;

use Siroko\Cart\Domain\Exception\InvalidPriceException;
use Siroko\Cart\Domain\Exception\InvalidProductCodeException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Exception\NameInvalidLengthException;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class CreateProductCommand
{
    private readonly ProductCode $code;

    private readonly Name $name;

    private readonly Price $price;

    private readonly Quantity $quantity;

    /**
     * Building the command validates it: every value object applies its own
     * rules, and the API maps each exception to a 400.
     *
     * @throws InvalidProductCodeException
     * @throws NameInvalidLengthException
     * @throws InvalidPriceException
     * @throws InvalidQuantityException
     */
    public function __construct(
        string $code,
        string $name,
        string $priceAmount,
        string $priceCurrency,
        int|string $quantity,
    ) {
        $this->code = ProductCode::fromString($code);
        $this->name = Name::fromString($name);
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
