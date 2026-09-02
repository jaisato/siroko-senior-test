<?php

namespace Siroko\Cart\Domain\Exception;

/**
 * A product was added to a cart with no units left to reserve.
 *
 * Adding used to reach `new Quantity(-1)`, so the caller got the Quantity value
 * object's own complaint - "Quantity must be greater or equal to 0", HTTP 400 -
 * which names an internal invariant rather than the thing that actually
 * happened, and reads like the request was malformed when it was perfectly
 * well formed and simply arrived too late.
 */
class OutOfStockException extends \DomainException
{
}
