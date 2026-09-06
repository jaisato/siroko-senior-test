<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

/**
 * The cart already holds as many distinct products as it accepts.
 *
 * A conflict rather than a bad request: the payload naming one more product is
 * perfectly well formed, and there is nothing in it for the client to correct.
 * What refuses it is the state of the cart, and what resolves it is emptying a
 * line or checking out - which is exactly the distinction 409 carries.
 *
 * The cap is not decoration. Price::MAX_AMOUNT is derived from it: the widest
 * total a valid cart can reach is Cart::MAX_LINES lines of
 * CartItem::MAX_QUANTITY units at the dearest price the column accepts, and
 * that product has to stay inside orders.total_amount. Let the line count grow
 * unchecked and a cart nothing refused fails at checkout with an out-of-range
 * error the client reads as a 500.
 */
final class CartIsFullException extends \DomainException
{
    public static function atLineLimit(int $max): self
    {
        return new self(\sprintf('A cart accepts at most %d distinct products; remove a line or check out.', $max));
    }
}
