<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Entity;

use Siroko\Cart\Domain\Exception\EmptyCartException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\OrderId;
use Siroko\Cart\Domain\ValueObject\OrderLine;
use Siroko\Cart\Domain\ValueObject\Price;

/**
 * What a checkout produced: the lines of the cart as they were paid, and the
 * total that was captured.
 *
 * Checking out used to flip the cart to "paid" and nothing else. The cart is a
 * working document - its lines point at products whose prices move - so once
 * the customer has paid there has to be a record that does not: this one. It
 * is written in the same transaction as the status change and is the source
 * of the `CartCheckedOut` event.
 *
 * Properties are not readonly: Doctrine re-hydrates them.
 */
class Order
{
    private ?\DateTimeImmutable $confirmedAt = null;

    /**
     * @param list<OrderLine> $lines
     */
    private function __construct(
        private OrderId $id,
        private CartId $cartId,
        private array $lines,
        private int $itemCount,
        private Price $total,
        private \DateTimeImmutable $createdAt,
    ) {}

    /**
     * Snapshots a cart that has just been paid.
     *
     * @throws InvalidCartStatusException when the cart has not been paid
     * @throws EmptyCartException         when the cart has no lines (Cart::pay() refuses those, so this is a guard)
     */
    public static function place(OrderId $id, Cart $cart, \DateTimeImmutable $now): self
    {
        if (!$cart->status()->isPaid()) {
            throw new InvalidCartStatusException('An order is placed for a paid cart.');
        }

        $total = $cart->total();

        if (null === $total) {
            throw EmptyCartException::cannotBePaid();
        }

        $lines = [];

        foreach ($cart->items() as $item) {
            $lines[] = OrderLine::fromCartItem($item);
        }

        return new self($id, $cart->id(), $lines, $cart->itemCount(), $total, $now);
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }

    /**
     * @return list<OrderLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function itemCount(): int
    {
        return $this->itemCount;
    }

    public function total(): Price
    {
        return $this->total;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function confirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->confirmedAt;
    }

    /**
     * Records that the customer was told about the order. Idempotent: the
     * confirmation is sent by a queue consumer, and a queue redelivers, so a
     * second call keeps the first timestamp and changes nothing.
     *
     * @return bool whether this call was the one that confirmed the order
     */
    public function confirm(\DateTimeImmutable $at): bool
    {
        if (null !== $this->confirmedAt) {
            return false;
        }

        $this->confirmedAt = $at;

        return true;
    }
}
