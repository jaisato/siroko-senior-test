<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Product;

use Siroko\Cart\Application\Dto\PriceFormatter;
use Siroko\Cart\Domain\Entity\Product;

final class ProductRead
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $code,
        public readonly string $price,
        public readonly int $quantity,
    ) {}

    public static function fromModel(Product $product): self
    {
        return new self(
            $product->id()->toString(),
            $product->name()->toString(),
            $product->code()->toString(),
            PriceFormatter::format($product->price()),
            $product->quantity()->asInt(),
        );
    }
}
