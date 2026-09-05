<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Cart;

use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;

/**
 * Read model of a cart, as the API returns it.
 *
 * It lives in the application layer because the handlers build it: the
 * previous location, next to the controllers, made every use case depend on
 * the HTTP adapter. The OpenAPI metadata that describes it stays in
 * Infrastructure (see the `Api\Resource` classes).
 */
final class CartRead
{
    /**
     * @var array<string, CartItemRead> keyed by item id
     */
    public readonly array $items;

    /**
     * @param iterable<CartItem> $items
     */
    public function __construct(
        public readonly string $id,
        public readonly int $status,
        iterable $items = [],
    ) {
        $read = [];

        foreach ($items as $item) {
            $read[$item->id()->toString()] = CartItemRead::fromModel($item);
        }

        $this->items = $read;
    }

    public static function fromModel(Cart $cart): self
    {
        return new self(
            $cart->id()->toString(),
            $cart->status()->toInt(),
            $cart->items(),
        );
    }
}
