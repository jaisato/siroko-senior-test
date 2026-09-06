<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\CancelCartCommand;
use Siroko\Cart\Application\Command\Cart\CancelCartCommandHandler;
use Siroko\Cart\Application\Service\CartCancellation;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\OrderRepository;
use Symfony\Component\Clock\MockClock;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\OrderId;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * Canceling a cart gives every reserved unit back, inside one transaction and
 * under the cart's lock, or a concurrent checkout could pay for units that are
 * on their way back to the shelf.
 */
final class CancelCartCommandHandlerTest extends TestCase
{
    /** @var list<array{0: string, 1: int}> stock credited, as (productId, units) */
    private array $returned = [];

    private RecordingSession $session;

    protected function setUp(): void
    {
        $this->returned = [];
        $this->session = new RecordingSession();
    }

    public function test_canceling_a_pending_cart_returns_every_unit_of_every_line(): void
    {
        $first = $this->product('11111111-1111-4111-8111-111111111111');
        $second = $this->product('22222222-2222-4222-8222-222222222222');
        $cart = $this->cart(CartStatus::PENDING, [[$first, 3], [$second, 1]]);

        $this->handler($cart)(new CancelCartCommand($cart->id()->toString()));

        self::assertSame(CartStatus::CANCELED, $cart->status()->toInt());
        self::assertSame([[$first->id()->toString(), 3], [$second->id()->toString(), 1]], $this->returned);
        self::assertSame(['begin', 'lockCart', 'returnStock', 'returnStock', 'saveCart', 'commit'], $this->session->log);
    }

    /** A paid cart called off: the units were sold but are not going anywhere, so they go back too. */
    public function test_canceling_a_paid_cart_returns_its_units_as_well(): void
    {
        $product = $this->product('11111111-1111-4111-8111-111111111111');
        $cart = $this->cart(CartStatus::PAID, [[$product, 2]]);

        $this->handler($cart)(new CancelCartCommand($cart->id()->toString()));

        self::assertSame(CartStatus::CANCELED, $cart->status()->toInt());
        self::assertSame([[$product->id()->toString(), 2]], $this->returned);
    }

    /**
     * The order is the record of what was bought, so calling the purchase off
     * has to reach it. Left standing it was still confirmed by the queued
     * CartCheckedOut consumer, which only asked whether the confirmation had
     * gone out, and every read of it showed a purchase the customer had
     * cancelled.
     */
    public function test_canceling_a_paid_cart_calls_its_order_off(): void
    {
        $product = $this->product('11111111-1111-4111-8111-111111111111');
        $cart = $this->cart(CartStatus::PAID, [[$product, 2]]);
        $order = Order::place(OrderId::fromString(Uuid::uuid4()->toString()), $cart, new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC')));

        $this->handler($cart, $order)(new CancelCartCommand($cart->id()->toString()));

        self::assertTrue($order->isCanceled());
        self::assertSame('2026-09-06 10:05:00', $order->canceledAt()?->format('Y-m-d H:i:s'));
        self::assertFalse($order->confirm(new \DateTimeImmutable('2026-09-06 11:00:00', new \DateTimeZone('UTC'))), 'the queued confirmation finds nothing to do');
        self::assertContains('saveOrder', $this->session->log);
    }

    /** A pending cart has no order behind it, and none is looked for. */
    public function test_canceling_a_pending_cart_touches_no_order(): void
    {
        $cart = $this->cart(CartStatus::PENDING, [[$this->product('11111111-1111-4111-8111-111111111111'), 1]]);

        $this->handler($cart)(new CancelCartCommand($cart->id()->toString()));

        self::assertNotContains('saveOrder', $this->session->log);
    }

    /**
     * Products are credited in id order whatever the order of the lines: two
     * cancellations of carts sharing products must not wait on each other.
     */
    public function test_stock_is_returned_in_product_id_order(): void
    {
        $low = $this->product('11111111-1111-4111-8111-111111111111');
        $high = $this->product('99999999-9999-4999-8999-999999999999');
        $cart = $this->cart(CartStatus::PENDING, [[$high, 1], [$low, 1]]);

        $this->handler($cart)(new CancelCartCommand($cart->id()->toString()));

        self::assertSame([$low->id()->toString(), $high->id()->toString()], array_column($this->returned, 0));
    }

    public function test_a_delivered_cart_cannot_be_canceled_and_nothing_moves(): void
    {
        $product = $this->product('11111111-1111-4111-8111-111111111111');
        $cart = $this->cart(CartStatus::DELIVERED, [[$product, 2]]);

        try {
            $this->handler($cart)(new CancelCartCommand($cart->id()->toString()));
            self::fail('expected an exception');
        } catch (InvalidCartStatusException) {
        }

        self::assertSame([], $this->returned);
        self::assertNotContains('saveCart', $this->session->log);
    }

    public function test_canceling_twice_is_a_conflict(): void
    {
        $cart = $this->cart(CartStatus::CANCELED, []);

        $this->expectException(InvalidCartStatusException::class);

        $this->handler($cart)(new CancelCartCommand($cart->id()->toString()));
    }

    public function test_an_unknown_cart_is_not_found(): void
    {
        $this->expectException(CartNotFoundException::class);

        $this->handler(null)(new CancelCartCommand(Uuid::uuid4()->toString()));
    }

    public function test_the_command_validates_its_identifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new CancelCartCommand('cart-1');
    }

    /**
     * @param list<array{0: Product, 1: int}> $lines
     */
    private function cart(int $status, array $lines): Cart
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());

        foreach ($lines as [$product, $units]) {
            $cart->addItem(new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity($units)));
        }

        if (CartStatus::PAID === $status || CartStatus::DELIVERED === $status) {
            $cart->pay();
        }

        if (CartStatus::DELIVERED === $status) {
            $cart->deliver();
        }

        if (CartStatus::CANCELED === $status) {
            $cart->cancel();
        }

        return $cart;
    }

    private function product(string $id): Product
    {
        return new Product(
            ProductId::fromString($id),
            ProductCode::fromString('SKU-' . random_int(1000, 9999)),
            Name::fromString('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity(50),
        );
    }

    private function handler(?Cart $cart, ?Order $order = null): CancelCartCommandHandler
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('ofIdForUpdate')->willReturnCallback(function () use ($cart): ?Cart {
            $this->session->log[] = 'lockCart';

            return $cart;
        });
        $carts->method('ofId')->willReturnCallback(static fn() => self::fail('the cart must be loaded with its row locked'));
        $carts->method('save')->willReturnCallback(function (): void {
            $this->session->log[] = 'saveCart';
        });

        $products = $this->createStub(ProductRepository::class);
        $products->method('returnStock')->willReturnCallback(function (ProductId $id, int $units): void {
            $this->returned[] = [$id->toString(), $units];
            $this->session->log[] = 'returnStock';
        });

        $orders = $this->createStub(OrderRepository::class);
        $orders->method('ofCart')->willReturn($order);
        $orders->method('save')->willReturnCallback(function (): void {
            $this->session->log[] = 'saveOrder';
        });

        return new CancelCartCommandHandler(
            $carts,
            new CartCancellation($carts, $products, $orders, new MockClock('2026-09-06 10:05:00', new \DateTimeZone('UTC'))),
            $this->session,
        );
    }
}
