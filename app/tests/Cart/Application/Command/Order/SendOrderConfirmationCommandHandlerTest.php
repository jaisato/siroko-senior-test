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
use Siroko\Tests\Cart\Application\Command\Cart\RecordingSession;
use Symfony\Component\Clock\MockClock;

/**
 * The worker-side handler of `CartCheckedOut`. A queue redelivers, so it has
 * to be safe to run twice for the same order.
 */
final class SendOrderConfirmationCommandHandlerTest extends TestCase
{
    private RecordingLogger $logger;

    private RecordingSession $session;

    private int $saves = 0;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->session = new RecordingSession();
        $this->saves = 0;
    }

    public function test_it_confirms_the_order_at_the_clock_time_and_logs_it(): void
    {
        $order = $this->order();
        $clock = new MockClock('2026-09-06 10:05:00', 'UTC');

        $this->handler($order, $clock)(new SendOrderConfirmationCommand($order->id()->toString()));

        self::assertTrue($order->isConfirmed());
        self::assertSame('2026-09-06T10:05:00+00:00', $order->confirmedAt()?->format(\DateTimeInterface::RFC3339));
        self::assertTrue($order->isConfirmationSent());
        self::assertSame(2, $this->saves, 'the decision and the delivery are two writes');
        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
        self::assertStringContainsString('sent', $this->logger->records[0]['message']);
        self::assertSame($order->id()->toString(), $this->logger->records[0]['context']['orderId']);
        self::assertSame('20.00 EUR', $this->logger->records[0]['context']['total']);

        // Decide and commit, send, record the send. The delivery sits between
        // two transactions rather than inside one: it cannot be rolled back,
        // and a commit that failed over it told the customer something the row
        // then denied.
        self::assertSame(
            ['begin', 'lockOrder', 'saveOrder', 'commit', 'begin', 'lockOrder', 'saveOrder', 'commit'],
            $this->session->log,
        );
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
        self::assertSame('2026-09-06T10:05:00+00:00', $order->confirmationSentAt()?->format(\DateTimeInterface::RFC3339));
        self::assertSame(2, $this->saves, 'the second run did not write');
        self::assertCount(2, $this->logger->records);
        self::assertStringContainsString('nothing to do', $this->logger->records[1]['message']);
        self::assertTrue($this->logger->records[1]['context']['sent']);
        self::assertFalse($this->logger->records[1]['context']['canceled']);
    }

    /**
     * The cancellation and this handler are two writers to one order row.
     * Read unlocked, each decided on the state it had read: the cancellation
     * set `canceled_at` while this handler, holding an order it had loaded as
     * neither confirmed nor cancelled, set `confirmed_at` on top, and the
     * customer was told a purchase they had called off was on its way. Under
     * the lock the queued run reads the cancellation and stands down.
     */
    public function test_an_order_cancelled_before_the_worker_ran_is_not_confirmed(): void
    {
        $order = $this->order();
        $order->cancel(new \DateTimeImmutable('2026-09-06 10:02:00', new \DateTimeZone('UTC')));

        $this->handler($order, new MockClock('2026-09-06 10:05:00', 'UTC'))(new SendOrderConfirmationCommand($order->id()->toString()));

        self::assertFalse($order->isConfirmed());
        self::assertSame(0, $this->saves);
        self::assertStringContainsString('nothing to do', $this->logger->records[0]['message']);
        self::assertTrue($this->logger->records[0]['context']['canceled']);
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
        $orders->method('ofIdForUpdate')->willReturnCallback(function () use ($order): ?Order {
            $this->session->log[] = 'lockOrder';

            return $order;
        });
        $orders->method('ofId')->willReturnCallback(static fn() => self::fail('the order must be loaded with its row locked'));
        $orders->method('save')->willReturnCallback(function (): void {
            ++$this->saves;
            $this->session->log[] = 'saveOrder';
        });

        return new SendOrderConfirmationCommandHandler($orders, $clock, $this->logger, $this->session);
    }
}
