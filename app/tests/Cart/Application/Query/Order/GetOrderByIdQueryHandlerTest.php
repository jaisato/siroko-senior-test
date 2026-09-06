<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Query\Order;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Query\Order\GetOrderByIdQuery;
use Siroko\Cart\Application\Query\Order\GetOrderByIdQueryHandler;
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

final class GetOrderByIdQueryHandlerTest extends TestCase
{
    public function test_it_reads_an_order_with_its_lines_and_total(): void
    {
        $order = $this->order();
        $order->confirm(new \DateTimeImmutable('2026-09-06 10:05:00', new \DateTimeZone('Europe/Madrid')));

        $orders = $this->createStub(OrderRepository::class);
        $orders->method('ofId')->willReturn($order);

        $read = (new GetOrderByIdQueryHandler($orders))(new GetOrderByIdQuery($order->id()->toString()));

        self::assertSame($order->id()->toString(), $read->id);
        self::assertSame($order->cartId()->toString(), $read->cartId);
        self::assertSame(2, $read->itemCount);
        self::assertSame(['amount' => '20.00', 'currency' => 'EUR'], $read->total);
        self::assertCount(1, $read->lines);
        self::assertSame('SKU', $read->lines[0]->code);
        self::assertSame(2, $read->lines[0]->quantity);
        self::assertSame(['amount' => '10.00', 'currency' => 'EUR'], $read->lines[0]->unitPrice);
        self::assertSame(['amount' => '20.00', 'currency' => 'EUR'], $read->lines[0]->lineTotal);
        self::assertSame('2026-09-06T10:00:00+00:00', $read->createdAt);
        self::assertSame('2026-09-06T08:05:00+00:00', $read->confirmedAt, 'instants are reported in UTC');
    }

    public function test_an_unconfirmed_order_has_no_confirmation_time(): void
    {
        $orders = $this->createStub(OrderRepository::class);
        $orders->method('ofId')->willReturn($this->order());

        $read = (new GetOrderByIdQueryHandler($orders))(new GetOrderByIdQuery(Uuid::uuid4()->toString()));

        self::assertNull($read->confirmedAt);
        self::assertNull($read->canceledAt);
    }

    /**
     * A cancelled order is never confirmed, so without `canceledAt` it reads
     * exactly like one still waiting for its worker: `confirmedAt: null` and
     * nothing else to tell a called-off purchase from a pending confirmation.
     */
    public function test_a_cancelled_order_says_when_it_was_called_off(): void
    {
        $order = $this->order();
        $order->cancel(new \DateTimeImmutable('2026-09-06 10:05:00', new \DateTimeZone('Europe/Madrid')));

        $orders = $this->createStub(OrderRepository::class);
        $orders->method('ofId')->willReturn($order);

        $read = (new GetOrderByIdQueryHandler($orders))(new GetOrderByIdQuery($order->id()->toString()));

        self::assertSame('2026-09-06T08:05:00+00:00', $read->canceledAt, 'instants are reported in UTC');
        self::assertNull($read->confirmedAt);
    }

    public function test_an_unknown_order_is_not_found(): void
    {
        $orders = $this->createStub(OrderRepository::class);
        $orders->method('ofId')->willReturn(null);

        $this->expectException(OrderNotFoundException::class);

        (new GetOrderByIdQueryHandler($orders))(new GetOrderByIdQuery(Uuid::uuid4()->toString()));
    }

    public function test_the_query_validates_its_identifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new GetOrderByIdQuery('order-1');
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
}
