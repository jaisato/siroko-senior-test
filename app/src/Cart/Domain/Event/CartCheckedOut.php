<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Event;

use Siroko\Cart\Domain\Entity\Order;

/**
 * A cart was paid for and an order placed for it.
 *
 * The first event the application raises. It carries plain values only: it
 * is serialised into the queue and read back by a worker that may be running
 * a different revision of the code, so it must not drag entities along.
 *
 * `commandArguments()` is what DomainEventConsumer hands to the command the
 * event is mapped to (see EventCommandFactory in the service configuration):
 * the order id is all the confirmation needs.
 */
final class CartCheckedOut implements DomainEvent
{
    public function __construct(
        private readonly string $orderId,
        private readonly string $cartId,
        private readonly string $totalAmount,
        private readonly string $totalCurrency,
        private readonly int $itemCount,
        private readonly int $occurredOn,
    ) {}

    public static function fromOrder(Order $order): self
    {
        return new self(
            $order->id()->toString(),
            $order->cartId()->toString(),
            $order->total()->amount(),
            $order->total()->currency()->getCurrencyCode(),
            $order->itemCount(),
            $order->createdAt()->getTimestamp(),
        );
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function cartId(): string
    {
        return $this->cartId;
    }

    public function ocurredOn(): int
    {
        return $this->occurredOn;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'orderId' => $this->orderId,
            'cartId' => $this->cartId,
            'total' => ['amount' => $this->totalAmount, 'currency' => $this->totalCurrency],
            'itemCount' => $this->itemCount,
            'occurredOn' => $this->occurredOn,
        ];
    }

    /**
     * @return string[]
     */
    public function commandArguments(): array
    {
        return [$this->orderId];
    }
}
