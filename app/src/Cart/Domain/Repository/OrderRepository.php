<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Repository;

use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\OrderId;

interface OrderRepository
{
    public function nextIdentity(): OrderId;

    public function save(Order $order): void;

    public function ofId(OrderId $id): ?Order;

    /**
     * The order placed for a cart, if it was checked out. A cart is paid once,
     * so there is at most one.
     */
    public function ofCart(CartId $cartId): ?Order;
}
