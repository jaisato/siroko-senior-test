<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Order;

use Siroko\Cart\Domain\Entity\Order;

/**
 * An order as the API returns it: the captured total, the lines as they were
 * paid, and when the customer was told about it.
 */
final class OrderRead
{
    /**
     * @param string|null                             $customerId  the owner of the cart that was paid; null for an ownerless cart
     * @param list<OrderLineRead>                     $lines
     * @param array{amount: string, currency: string} $total
     * @param string                                  $createdAt   RFC 3339, UTC
     * @param string|null                             $confirmedAt RFC 3339, UTC; null until the confirmation went out
     * @param string|null                             $canceledAt  RFC 3339, UTC; null unless the purchase was called off
     */
    public function __construct(
        public readonly string $id,
        public readonly string $cartId,
        public readonly ?string $customerId,
        public readonly int $itemCount,
        public readonly array $total,
        public readonly array $lines,
        public readonly string $createdAt,
        public readonly ?string $confirmedAt,
        public readonly ?string $canceledAt,
    ) {}

    public static function fromModel(Order $order): self
    {
        $lines = [];

        foreach ($order->lines() as $line) {
            $lines[] = OrderLineRead::fromModel($line);
        }

        return new self(
            id: $order->id()->toString(),
            cartId: $order->cartId()->toString(),
            customerId: $order->customerId()?->toString(),
            itemCount: $order->itemCount(),
            total: $order->total()->jsonSerialize(),
            lines: $lines,
            createdAt: self::utc($order->createdAt()),
            confirmedAt: null === $order->confirmedAt() ? null : self::utc($order->confirmedAt()),
            // Without it a cancelled order reads exactly like one still
            // waiting for its worker: `confirmedAt: null` and nothing else to
            // tell the two apart.
            canceledAt: null === $order->canceledAt() ? null : self::utc($order->canceledAt()),
        );
    }

    private static function utc(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339);
    }
}
