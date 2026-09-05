<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Cart;

use Siroko\Cart\Application\Dto\PriceFormatter;
use Siroko\Cart\Domain\Entity\CartItem;

/**
 * One line of a cart as the API returns it.
 *
 * `price` is the unit price localised for display, as it has always been;
 * `unitPrice` and `lineTotal` are the same money as `{amount, currency}`
 * pairs a client can compute with.
 */
final class CartItemRead
{
    /**
     * @param array{amount: string, currency: string} $unitPrice
     * @param array{amount: string, currency: string} $lineTotal
     */
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly string $name,
        public readonly string $code,
        public readonly string $price,
        public readonly int $quantity,
        public readonly array $unitPrice,
        public readonly array $lineTotal,
    ) {}

    public static function fromModel(CartItem $item): self
    {
        $product = $item->getProduct();

        return new self(
            id: $item->id()->toString(),
            productId: $product->id()->toString(),
            name: $product->name()->toString(),
            code: $product->code()->toString(),
            price: PriceFormatter::format($product->price()),
            quantity: $item->quantity()->asInt(),
            unitPrice: $product->price()->jsonSerialize(),
            lineTotal: $item->total()->jsonSerialize(),
        );
    }
}
