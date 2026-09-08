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
     * The same order, with its row locked until the caller's transaction ends.
     *
     * Confirming an order and cancelling it are two writers to one row that
     * must not both decide on the state they read: the confirmation would
     * otherwise read an order that is about to be cancelled, and send a
     * customer the confirmation of a purchase they called off.
     */
    public function ofIdForUpdate(OrderId $id): ?Order;

    /**
     * The order placed for a cart, if it was checked out. A cart is paid once,
     * so there is at most one.
     */
    public function ofCart(CartId $cartId): ?Order;

    /** As {@see ofCart}, with the row locked - the cancelling side of the pair above. */
    public function ofCartForUpdate(CartId $cartId): ?Order;
}
