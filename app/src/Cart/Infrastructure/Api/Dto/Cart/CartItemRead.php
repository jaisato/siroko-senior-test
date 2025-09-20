<?php

namespace Siroko\Cart\Infrastructure\Api\Dto\Cart;

use Siroko\Cart\Domain\Entity\CartItem;

final class CartItemRead
{
    /**
     * @param string $id
     * @param string $name
     * @param string $code
     * @param string $price
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $code,
        public readonly string $price,
    ) {
    }

    /**
     * @param CartItem $item
     * @return self
     * @throws \Brick\Money\Exception\UnknownCurrencyException
     */
    public static function fromModel(CartItem $item): self
    {
        return new self(
            id: $item->id()->toString(),
            name: $item->getProduct()->name()->toString(),
            code: $item->getProduct()->code()->toString(),
            price: $item->getProduct()->price()->toMoney()->formatTo('es_ES'),
        );
    }
}
