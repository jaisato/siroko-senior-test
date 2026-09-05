<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Exception\InvalidPriceException;

/**
 * What one line of a cart looked like at the moment it was paid for.
 *
 * A cart line points at a product, and a product changes: its price, its
 * name, eventually its existence. The order has to remember what the customer
 * actually bought and paid, so it keeps copies of the values, not references.
 *
 * @phpstan-type OrderLineArray array{
 *     productId: string,
 *     code: string,
 *     name: string,
 *     quantity: int,
 *     unitPrice: array{amount: string, currency: string},
 *     lineTotal: array{amount: string, currency: string}
 * }
 */
final class OrderLine
{
    /**
     * @param positive-int $quantity
     */
    private function __construct(
        private readonly string $productId,
        private readonly string $code,
        private readonly string $name,
        private readonly int $quantity,
        private readonly Price $unitPrice,
        private readonly Price $lineTotal,
    ) {}

    public static function fromCartItem(CartItem $item): self
    {
        $product = $item->getProduct();

        return new self(
            $product->id()->toString(),
            $product->code()->toString(),
            $product->name()->toString(),
            $item->units(),
            $product->price(),
            $item->total(),
        );
    }

    /**
     * Rebuilds a line from its stored form. The amounts were valid prices when
     * they were written, so a failure here is a corrupt row, not bad input.
     *
     * @param OrderLineArray $data
     *
     * @throws InvalidPriceException
     */
    public static function fromArray(array $data): self
    {
        $quantity = max(1, $data['quantity']);

        return new self(
            $data['productId'],
            $data['code'],
            $data['name'],
            $quantity,
            Price::of($data['unitPrice']['amount'], $data['unitPrice']['currency']),
            Price::of($data['lineTotal']['amount'], $data['lineTotal']['currency']),
        );
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return positive-int
     */
    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPrice(): Price
    {
        return $this->unitPrice;
    }

    public function lineTotal(): Price
    {
        return $this->lineTotal;
    }

    /**
     * @return OrderLineArray
     */
    public function toArray(): array
    {
        return [
            'productId' => $this->productId,
            'code' => $this->code,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice->jsonSerialize(),
            'lineTotal' => $this->lineTotal->jsonSerialize(),
        ];
    }
}
