<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Cart;

use Siroko\Cart\Application\Dto\Order\OrderRead;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\Order;

/**
 * What a checkout answers: the cart as it now stands (paid) and the order
 * that was placed for it. The order id is what the client keeps.
 */
final class CheckoutRead
{
    public function __construct(
        public readonly CartRead $cart,
        public readonly OrderRead $order,
    ) {}

    public static function fromModels(Cart $cart, Order $order): self
    {
        return new self(CartRead::fromModel($cart), OrderRead::fromModel($order));
    }
}
