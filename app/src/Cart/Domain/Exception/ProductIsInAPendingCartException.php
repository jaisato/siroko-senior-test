<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

use Brick\Money\Currency;

/**
 * The product cannot be repriced into another currency while a cart holds it.
 *
 * A cart line points at the product rather than carrying a copy of its price,
 * which is deliberate - the customer sees the current price - but it makes the
 * cart's "one currency" rule something a later edit can break. Repriced from
 * EUR to USD while a pending cart holds it alongside a euro product,
 * `Cart::subtotal()` throws from then on: reading the cart answers 409 and
 * checkout rolls back, every time, and the customer has no way out except
 * cancelling the cart or waiting for it to expire.
 *
 * A conflict, then, and one the administrator resolves by changing the price
 * within its currency, or by waiting for the carts holding it to settle.
 */
final class ProductIsInAPendingCartException extends \DomainException
{
    public static function cannotChangeCurrency(Currency $from, Currency $to): self
    {
        return new self(\sprintf(
            'This product is in a pending cart, so its currency cannot change from %s to %s; '
            . 'change the amount instead, or wait for those carts to be paid, cancelled or expired.',
            $from->getCurrencyCode(),
            $to->getCurrencyCode(),
        ));
    }
}
