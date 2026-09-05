<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Cart;

use Siroko\Cart\Application\Dto\PriceFormatter;
use Siroko\Cart\Domain\Entity\CartItem;

final class CartItemRead
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $code,
        public readonly string $price,
    ) {}

    public static function fromModel(CartItem $item): self
    {
        $product = $item->getProduct();

        return new self(
            id: $item->id()->toString(),
            name: $product->name()->toString(),
            code: $product->code()->toString(),
            price: PriceFormatter::format($product->price()),
        );
    }
}
