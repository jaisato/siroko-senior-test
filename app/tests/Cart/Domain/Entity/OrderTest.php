<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\Entity;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Event\CartCheckedOut;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\OrderId;
use Siroko\Cart\Domain\ValueObject\OrderLine;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * An order is what a checkout leaves behind: the lines as they were paid and
 * the total that was captured, frozen.
 */
final class OrderTest extends TestCase
{
    private const NOW = '2026-09-06 10:00:00';

    public function test_placing_an_order_snapshots_a_paid_cart(): void
    {
        $cart = self::paidCart([['Gafas', 'K3', '129.95', 2], ['Funda', 'F1', '9.99', 1]]);
        $id = OrderId::fromString(Uuid::uuid4()->toString());

        $order = Order::place($id, $cart, self::now());

        self::assertSame($id, $order->id());
        self::assertTrue($cart->id()->equals($order->cartId()));
        self::assertSame(3, $order->itemCount());
        self::assertTrue(Price::of('269.89', 'EUR')->equals($order->total()));
        self::assertSame(self::NOW, $order->createdAt()->format('Y-m-d H:i:s'));
        self::assertNull($order->confirmedAt());
        self::assertFalse($order->isConfirmed());

        $lines = $order->lines();
        self::assertCount(2, $lines);
        self::assertContainsOnlyInstancesOf(OrderLine::class, $lines);
        self::assertSame('Gafas', $lines[0]->name());
        self::assertSame('K3', $lines[0]->code());
        self::assertSame(2, $lines[0]->quantity());
        self::assertSame('129.95', $lines[0]->unitPrice()->amount());
        self::assertSame('259.90', $lines[0]->lineTotal()->amount());
    }

    /** The lines are values, not references: the product moving on does not rewrite the order. */
    public function test_the_snapshot_does_not_follow_the_product(): void
    {
        $cart = self::paidCart([['Gafas', 'K3', '129.95', 1]]);
        $item = $cart->items()->first();
        self::assertInstanceOf(CartItem::class, $item);

        $order = Order::place(OrderId::fromString(Uuid::uuid4()->toString()), $cart, self::now());
        $item->getProduct()->setPrice(Price::of('1.00', 'EUR'));

        self::assertSame('129.95', $order->lines()[0]->unitPrice()->amount());
        self::assertSame('129.95', $order->total()->amount());
    }

    public function test_an_order_is_placed_for_a_paid_cart_only(): void
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());
        $cart->addItem(new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product('Gafas', 'K3', '129.95')));

        $this->expectException(InvalidCartStatusException::class);

        Order::place(OrderId::fromString(Uuid::uuid4()->toString()), $cart, self::now());
    }

    public function test_confirming_is_idempotent(): void
    {
        $order = Order::place(OrderId::fromString(Uuid::uuid4()->toString()), self::paidCart([['Gafas', 'K3', '10.00', 1]]), self::now());
        $first = new \DateTimeImmutable('2026-09-06 10:05:00', new \DateTimeZone('UTC'));
        $second = new \DateTimeImmutable('2026-09-06 11:00:00', new \DateTimeZone('UTC'));

        self::assertTrue($order->confirm($first));
        self::assertFalse($order->confirm($second), 'the second call is a no-op');
        self::assertSame($first, $order->confirmedAt());
        self::assertTrue($order->isConfirmed());
    }

    public function test_a_line_round_trips_through_its_array_form(): void
    {
        $cart = self::paidCart([['Gafas', 'K3', '129.95', 2]]);
        $item = $cart->items()->first();
        self::assertInstanceOf(CartItem::class, $item);
        $line = OrderLine::fromCartItem($item);

        $rebuilt = OrderLine::fromArray($line->toArray());

        self::assertSame($line->toArray(), $rebuilt->toArray());
        self::assertSame([
            'productId' => $item->getProduct()->id()->toString(),
            'code' => 'K3',
            'name' => 'Gafas',
            'quantity' => 2,
            'unitPrice' => ['amount' => '129.95', 'currency' => 'EUR'],
            'lineTotal' => ['amount' => '259.90', 'currency' => 'EUR'],
        ], $line->toArray());
    }

    public function test_the_checked_out_event_carries_the_order_as_plain_values(): void
    {
        $order = Order::place(OrderId::fromString(Uuid::uuid4()->toString()), self::paidCart([['Gafas', 'K3', '129.95', 2]]), self::now());

        $event = CartCheckedOut::fromOrder($order);

        self::assertSame($order->id()->toString(), $event->orderId());
        self::assertSame($order->cartId()->toString(), $event->cartId());
        self::assertSame($order->createdAt()->getTimestamp(), $event->ocurredOn());
        self::assertSame([$order->id()->toString()], $event->commandArguments(), 'what the confirmation command is built from');
        self::assertSame([
            'orderId' => $order->id()->toString(),
            'cartId' => $order->cartId()->toString(),
            'total' => ['amount' => '259.90', 'currency' => 'EUR'],
            'itemCount' => 2,
            'occurredOn' => $order->createdAt()->getTimestamp(),
        ], $event->jsonSerialize());

        // It travels through a queue as a PHP-serialised payload.
        $copy = unserialize(serialize($event));
        self::assertInstanceOf(CartCheckedOut::class, $copy);
        self::assertSame($event->jsonSerialize(), $copy->jsonSerialize());
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: int}> $lines (name, code, unit price, units)
     */
    private static function paidCart(array $lines): Cart
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());

        foreach ($lines as [$name, $code, $amount, $units]) {
            $cart->addItem(new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product($name, $code, $amount), new Quantity($units)));
        }

        $cart->pay();

        return $cart;
    }

    private static function product(string $name, string $code, string $amount): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString($code),
            Name::fromString($name),
            Price::of($amount, 'EUR'),
            new Quantity(10),
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }
}
