<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Entity;

use Siroko\Cart\Domain\Exception\EmptyCartException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\OrderNotFoundException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CustomerId;
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
     * When the purchase was called off, if it was.
     *
     * A paid cart can be cancelled, and the order is the record of what was
     * bought: leaving it untouched meant the queued confirmation - which only
     * asked whether it had already been sent - went out for a purchase that no
     * longer existed, and every read of the order still showed it as standing.
     */
    private ?\DateTimeImmutable $canceledAt = null;

    /** When the confirmation actually went out; see markConfirmationSent(). */
    private ?\DateTimeImmutable $confirmationSentAt = null;

    /**
     * @param list<OrderLine> $lines
     */
    private function __construct(
        private OrderId $id,
        private CartId $cartId,
        private ?CustomerId $customerId,
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

        return new self($id, $cart->id(), $cart->customerId(), $lines, $cart->itemCount(), $total, $now);
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }

    public function customerId(): ?CustomerId
    {
        return $this->customerId;
    }

    /**
     * The same rule as the cart it came from: an owner's order is theirs
     * alone, an ownerless one is open, and no caller means no scoping.
     */
    public function isAccessibleBy(?CustomerId $caller): bool
    {
        return null === $caller || null === $this->customerId || $this->customerId->equals($caller);
    }

    /**
     * @throws OrderNotFoundException so that another customer's order id is not confirmed to exist
     */
    public function ensureAccessibleBy(?CustomerId $caller): void
    {
        if (!$this->isAccessibleBy($caller)) {
            throw OrderNotFoundException::withId($this->id);
        }
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

    public function canceledAt(): ?\DateTimeImmutable
    {
        return $this->canceledAt;
    }

    public function isCanceled(): bool
    {
        return null !== $this->canceledAt;
    }

    /**
     * Records that the customer was told about the order. Idempotent: the
     * confirmation is sent by a queue consumer, and a queue redelivers, so a
     * second call keeps the first timestamp and changes nothing.
     *
     * A cancelled order is never confirmed. The confirmation is queued at
     * checkout and consumed later, so a cancellation landing in between used
     * to be overtaken by it: the purchase was called off and the customer
     * confirmed of it anyway.
     *
     * @return bool whether this call was the one that confirmed the order
     */
    public function confirm(\DateTimeImmutable $at): bool
    {
        if (null !== $this->confirmedAt || null !== $this->canceledAt) {
            return false;
        }

        $this->confirmedAt = $at;

        return true;
    }

    /**
     * Calls the purchase off. Idempotent, like confirm(), and for the same
     * reason: cancelling a cart twice must not move the timestamp.
     *
     * @return bool whether this call was the one that cancelled the order
     */
    public function cancel(\DateTimeImmutable $at): bool
    {
        if (null !== $this->canceledAt) {
            return false;
        }

        $this->canceledAt = $at;

        return true;
    }

    /**
     * Records that the confirmation actually went out, which is not the same
     * fact as `confirmedAt` and is why it is a second timestamp.
     *
     * `confirmedAt` is the decision, taken inside the transaction that owns the
     * row. The delivery happens outside it - it is a call to somewhere else,
     * and nothing about it can be rolled back - so it cannot be part of that
     * commit. Emitted inside it, as this used to be, a commit that failed
     * afterwards rolled the decision back while the customer had already been
     * told, and the retry told them a second time; the row then said the
     * confirmation had never been sent.
     *
     * With the two apart the order of events is: decide and commit, send, then
     * record the send. A redelivery reads both marks and knows which step is
     * still owed. The one window left is the queue's own - a crash between the
     * send and the ack - which no application can close.
     *
     * @return bool whether this call was the one that recorded it
     */
    public function markConfirmationSent(\DateTimeImmutable $at): bool
    {
        if (null !== $this->confirmationSentAt) {
            return false;
        }

        $this->confirmationSentAt = $at;

        return true;
    }

    public function confirmationSentAt(): ?\DateTimeImmutable
    {
        return $this->confirmationSentAt;
    }

    /** Whether the customer has already been told; see markConfirmationSent(). */
    public function isConfirmationSent(): bool
    {
        return null !== $this->confirmationSentAt;
    }
}
