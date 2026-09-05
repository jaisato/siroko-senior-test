<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Order;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Order\SendOrderConfirmationCommand;
use Siroko\Cart\Application\Command\Order\SendOrderConfirmationCommandHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\OrderNotFoundException;
use Siroko\Cart\Domain\Repository\OrderRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\OrderId;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Symfony\Component\Clock\MockClock;

/**
 * The worker-side handler of `CartCheckedOut`. A queue redelivers, so it has
 * to be safe to run twice for the same order.
 */
final class SendOrderConfirmationCommandHandlerTest extends TestCase
{
    private RecordingLogger $logger;

    private int $saves = 0;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->saves = 0;
    }

    public function test_it_confirms_the_order_at_the_clock_time_and_logs_it(): void
    {
        $order = $this->order();
        $clock = new MockClock('2026-09-06 10:05:00', 'UTC');

        $this->handler($order, $clock)(new SendOrderConfirmationCommand($order->id()->toString()));

        self::assertTrue($order->isConfirmed());
        self::assertSame('2026-09-06T10:05:00+00:00', $order->confirmedAt()?->format(\DateTimeInterface::RFC3339));
        self::assertSame(1, $this->saves);
        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
        self::assertStringContainsString('sent', $this->logger->records[0]['message']);
        self::assertSame($order->id()->toString(), $this->logger->records[0]['context']['orderId']);
        self::assertSame('20.00 EUR', $this->logger->records[0]['context']['total']);
    }

    /** Redelivery: the first timestamp stands and nothing is written again. */
    public function test_a_second_run_for_the_same_order_changes_nothing(): void
    {
        $order = $this->order();
        $first = new MockClock('2026-09-06 10:05:00', 'UTC');
        $second = new MockClock('2026-09-06 11:00:00', 'UTC');

        $this->handler($order, $first)(new SendOrderConfirmationCommand($order->id()->toString()));
        $this->handler($order, $second)(new SendOrderConfirmationCommand($order->id()->toString()));

        self::assertSame('2026-09-06T10:05:00+00:00', $order->confirmedAt()?->format(\DateTimeInterface::RFC3339));
        self::assertSame(1, $this->saves, 'the second run did not write');
        self::assertCount(2, $this->logger->records);
        self::assertStringContainsString('already sent', $this->logger->records[1]['message']);
    }

    /** An unknown order is an error the queue should see (retry, then park), not a silent drop. */
    public function test_an_unknown_order_is_not_found(): void
    {
        $this->expectException(OrderNotFoundException::class);

        $this->handler(null, new MockClock())(new SendOrderConfirmationCommand(Uuid::uuid4()->toString()));
    }

    public function test_the_command_validates_its_identifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new SendOrderConfirmationCommand('order-1');
    }

    private function order(): Order
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());
        $product = new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('SKU'),
            Name::fromString('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity(5),
        );
        $cart->addItem(new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(2)));
        $cart->pay();

        return Order::place(OrderId::fromString(Uuid::uuid4()->toString()), $cart, new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC')));
    }

    private function handler(?Order $order, MockClock $clock): SendOrderConfirmationCommandHandler
    {
        $orders = $this->createStub(OrderRepository::class);
        $orders->method('ofId')->willReturn($order);
        $orders->method('save')->willReturnCallback(function (): void {
            ++$this->saves;
        });

        return new SendOrderConfirmationCommandHandler($orders, $clock, $this->logger);
    }
}
