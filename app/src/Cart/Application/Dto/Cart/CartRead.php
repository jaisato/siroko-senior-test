<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Cart;

use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Exception\PriceIsNotSameCurrencyException;
use Siroko\Cart\Domain\ValueObject\Price;

/**
 * Read model of a cart, as the API returns it.
 *
 * It lives in the application layer because the handlers build it: the
 * previous location, next to the controllers, made every use case depend on
 * the HTTP adapter. The OpenAPI metadata that describes it stays in
 * Infrastructure (see the `Api\Resource` classes).
 *
 * The lines are a list. They used to be an object keyed by line id, which
 * serialised as `{}` for an empty cart and as `[]` for... nothing, since the
 * keys were never sequential; a client had to handle two shapes for one
 * field. Amounts come as `{amount, currency}` pairs, the shape of the Price
 * value object, so a client can add them up; the localised `price` string of
 * each line stays for display.
 *
 * @phpstan-type Money array{amount: string, currency: string}
 */
final class CartRead
{
    /**
     * @param list<CartItemRead> $items
     * @param int<0, max>        $itemCount units across every line
     * @param string|null        $currency  ISO 4217 code every line is priced in; null while the cart is empty
     * @param Money|null         $subtotal  sum of the line totals; null while the cart is empty
     * @param Money|null         $total     what the customer pays; equals the subtotal until taxes or discounts exist
     */
    public function __construct(
        public readonly string $id,
        public readonly int $status,
        public readonly array $items = [],
        public readonly int $itemCount = 0,
        public readonly ?string $currency = null,
        public readonly ?array $subtotal = null,
        public readonly ?array $total = null,
    ) {}

    /**
     * @throws PriceIsNotSameCurrencyException if the cart's lines are in two currencies
     */
    public static function fromModel(Cart $cart): self
    {
        $items = [];

        foreach ($cart->items() as $item) {
            $items[] = CartItemRead::fromModel($item);
        }

        return new self(
            id: $cart->id()->toString(),
            status: $cart->status()->toInt(),
            items: $items,
            itemCount: $cart->itemCount(),
            currency: $cart->currency()?->getCurrencyCode(),
            subtotal: self::money($cart->subtotal()),
            total: self::money($cart->total()),
        );
    }

    /**
     * @return Money|null
     */
    private static function money(?Price $price): ?array
    {
        return $price?->jsonSerialize();
    }
}
