<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Product;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\InvalidPriceException;
use Siroko\Cart\Domain\Exception\InvalidProductCodeException;
use Siroko\Cart\Domain\Exception\InvalidProductUpdateException;
use Siroko\Cart\Domain\Exception\NameInvalidLengthException;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;

/**
 * A partial update of a product: whichever of name, code and price the caller
 * sent. Stock is not among them - it has its own command, because it moves.
 */
final class UpdateProductCommand
{
    private readonly ProductId $id;

    private readonly ?Name $name;

    private readonly ?ProductCode $code;

    private readonly ?Price $price;

    /**
     * Building the command validates it: every value object applies its own
     * rules, and the API maps each exception to a 400.
     *
     * @throws InvalidIdentifierException
     * @throws InvalidProductUpdateException when nothing is to be changed, or the price is half there
     * @throws NameInvalidLengthException
     * @throws InvalidProductCodeException
     * @throws InvalidPriceException
     */
    public function __construct(
        string $id,
        ?string $name = null,
        ?string $code = null,
        ?string $priceAmount = null,
        ?string $priceCurrency = null,
    ) {
        $this->id = ProductId::fromString($id);

        if (null === $name && null === $code && null === $priceAmount && null === $priceCurrency) {
            throw InvalidProductUpdateException::nothingToChange();
        }

        if ((null === $priceAmount) !== (null === $priceCurrency)) {
            throw InvalidProductUpdateException::priceNeedsAmountAndCurrency();
        }

        $this->name = null === $name ? null : Name::fromString($name);
        $this->code = null === $code ? null : ProductCode::fromString($code);
        $this->price = null === $priceAmount || null === $priceCurrency ? null : Price::of($priceAmount, $priceCurrency);
    }

    public function id(): ProductId
    {
        return $this->id;
    }

    public function name(): ?Name
    {
        return $this->name;
    }

    public function code(): ?ProductCode
    {
        return $this->code;
    }

    public function price(): ?Price
    {
        return $this->price;
    }
}
