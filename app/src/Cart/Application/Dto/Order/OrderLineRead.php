<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Order;

use Siroko\Cart\Domain\ValueObject\OrderLine;

final class OrderLineRead
{
    /**
     * @param array{amount: string, currency: string} $unitPrice
     * @param array{amount: string, currency: string} $lineTotal
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $code,
        public readonly string $name,
        public readonly int $quantity,
        public readonly array $unitPrice,
        public readonly array $lineTotal,
    ) {}

    public static function fromModel(OrderLine $line): self
    {
        return new self(
            productId: $line->productId(),
            code: $line->code(),
            name: $line->name(),
            quantity: $line->quantity(),
            unitPrice: $line->unitPrice()->jsonSerialize(),
            lineTotal: $line->lineTotal()->jsonSerialize(),
        );
    }
}
