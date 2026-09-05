<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\CheckoutCartCommand;
use Siroko\Cart\Application\Command\Cart\CheckoutCartCommandHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Event\CartCheckedOut;
use Siroko\Cart\Domain\Event\DomainEventPublisher;
use Siroko\Cart\Domain\Event\Subscriber\InMemoryAllSubscriber;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\EmptyCartException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Repository\CartRepository;
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
 * Cobrar un carrito es leer su estado y escribirlo. Leyendo sin bloqueo, la
 * comprobación y la escritura son dos operaciones separadas y cualquier cosa
 * puede pasar entre medias:
 *
 * - dos checkouts simultáneos leen los dos el carrito pendiente y los dos lo
 *   cobran, de modo que el 409 que debía recibir el segundo nunca llega;
 * - un DELETE de línea en marcha también lo lee pendiente, así que el checkout
 *   confirma un carrito pagado que todavía contiene la línea y el borrado
 *   devuelve después al stock una unidad ya vendida.
 *
 * Por eso el carrito se carga con su fila bloqueada, y dentro de una
 * transacción: sin ella el bloqueo no dura hasta la escritura.
 *
 * The checkout now also places an order and raises `CartCheckedOut`; both are
 * pinned here, on the handler alone.
 */
final class CheckoutCartCommandHandlerTest extends TestCase
{
    private RecordingSession $session;

    private MockClock $clock;

    private InMemoryAllSubscriber $published;

    private int $subscription;

    /** @var list<Order> */
    private array $savedOrders = [];

    protected function setUp(): void
    {
        $this->session = new RecordingSession();
        $this->clock = new MockClock('2026-09-06 10:00:00', 'UTC');
        $this->savedOrders = [];
        $this->published = new InMemoryAllSubscriber();
        $this->subscription = DomainEventPublisher::instance()->subscribe($this->published);
    }

    protected function tearDown(): void
    {
        DomainEventPublisher::instance()->unsubscribe($this->subscription);
    }

    public function test_a_pending_cart_becomes_paid_and_an_order_is_placed_for_it(): void
    {
        $cart = $this->cartWith([['129.95', 2], ['9.99', 1]]);

        $read = $this->handler($cart)(new CheckoutCartCommand($cart->id()->toString()));

        self::assertSame(CartStatus::PAID, $cart->status()->toInt());
        self::assertSame(CartStatus::PAID, $read->cart->status);
        self::assertSame($cart->id()->toString(), $read->cart->id);

        self::assertCount(1, $this->savedOrders);
        $order = $this->savedOrders[0];
        self::assertSame($order->id()->toString(), $read->order->id);
        self::assertTrue($cart->id()->equals($order->cartId()));
        self::assertSame(3, $order->itemCount());
        self::assertTrue(Price::of('269.89', 'EUR')->equals($order->total()));
        self::assertCount(2, $order->lines());
        self::assertSame('2026-09-06T10:00:00+00:00', $order->createdAt()->format(\DateTimeInterface::RFC3339), 'the clock, not the wall');
        self::assertNull($order->confirmedAt());
        self::assertSame(['amount' => '269.89', 'currency' => 'EUR'], $read->order->total);
    }

    /** The order snapshots the lines: later changes to the products do not reach it. */
    public function test_the_order_keeps_the_lines_as_they_were_paid(): void
    {
        $cart = $this->cartWith([['129.95', 2]]);
        $product = $cart->items()->first();
        self::assertInstanceOf(CartItem::class, $product);

        $this->handler($cart)(new CheckoutCartCommand($cart->id()->toString()));

        $product->getProduct()->setPrice(Price::of('1.00', 'EUR'));

        $line = $this->savedOrders[0]->lines()[0];
        self::assertSame('129.95', $line->unitPrice()->amount());
        self::assertSame('259.90', $line->lineTotal()->amount());
        self::assertSame(2, $line->quantity());
        self::assertSame($product->getProduct()->id()->toString(), $line->productId());
    }

    /** The first real domain event: raised inside the command, with the order's identity. */
    public function test_cart_checked_out_is_published_with_the_order(): void
    {
        $cart = $this->cartWith([['10.00', 1]]);

        $this->handler($cart)(new CheckoutCartCommand($cart->id()->toString()));

        $events = $this->published->events();
        self::assertCount(1, $events);
        self::assertInstanceOf(CartCheckedOut::class, $events[0]);
        self::assertSame($this->savedOrders[0]->id()->toString(), $events[0]->orderId());
        self::assertSame($cart->id()->toString(), $events[0]->cartId());
        self::assertSame([$this->savedOrders[0]->id()->toString()], $events[0]->commandArguments());
        self::assertSame($this->clock->now()->getTimestamp(), $events[0]->ocurredOn());
    }

    public function test_the_cart_is_read_under_a_row_lock_inside_the_transaction(): void
    {
        $cart = $this->cartWith([['10.00', 1]]);

        $this->handler($cart)(new CheckoutCartCommand($cart->id()->toString()));

        self::assertSame(1, $this->session->transactions, 'exactly one transaction was opened');
        self::assertSame(
            ['begin', 'lockCart', 'saveCart', 'saveOrder', 'commit'],
            $this->session->log,
            'the lock, the status change and the order share one transaction',
        );
    }

    /** Cobrar dos veces es un conflicto del cliente, no un fallo del servidor. */
    public function test_a_cart_that_is_already_paid_is_refused_and_not_written(): void
    {
        $cart = $this->cartWith([['10.00', 1]], CartStatus::PAID);

        try {
            $this->handler($cart)(new CheckoutCartCommand($cart->id()->toString()));
            self::fail('expected an exception');
        } catch (InvalidCartStatusException) {
        }

        self::assertNotContains('saveCart', $this->session->log);
        self::assertSame([], $this->savedOrders);
        self::assertSame([], $this->published->events());
    }

    /** A payment for nothing: refused, and nothing is written or announced. */
    public function test_an_empty_cart_is_refused(): void
    {
        $cart = $this->cartWith([]);

        try {
            $this->handler($cart)(new CheckoutCartCommand($cart->id()->toString()));
            self::fail('expected an exception');
        } catch (EmptyCartException) {
        }

        self::assertTrue($cart->isPending());
        self::assertNotContains('saveCart', $this->session->log);
        self::assertSame([], $this->savedOrders);
        self::assertSame([], $this->published->events());
    }

    public function test_an_unknown_cart_is_not_found(): void
    {
        $this->expectException(CartNotFoundException::class);

        $this->handler(null)(new CheckoutCartCommand(Uuid::uuid4()->toString()));
    }

    public function test_the_command_validates_its_identifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new CheckoutCartCommand('cart-1');
    }

    /**
     * @param list<array{0: string, 1: int}> $lines (unit price, units)
     */
    private function cartWith(array $lines, int $status = CartStatus::PENDING): Cart
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());

        foreach ($lines as [$amount, $units]) {
            $product = new Product(
                ProductId::fromString(Uuid::uuid4()->toString()),
                ProductCode::fromString('SKU-' . random_int(1000, 9999)),
                Name::fromString('A product'),
                Price::of($amount, 'EUR'),
                new Quantity(50),
            );
            $cart->addItem(new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity($units)));
        }

        if (CartStatus::PAID === $status) {
            $cart->pay();
        }

        return $cart;
    }

    private function handler(?Cart $cart): CheckoutCartCommandHandler
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('ofIdForUpdate')->willReturnCallback(
            function () use ($cart): ?Cart {
                $this->session->log[] = 'lockCart';

                return $cart;
            },
        );
        // El camino sin bloqueo no debe usarse.
        $carts->method('ofId')->willReturnCallback(
            static fn() => self::fail('the cart must be loaded with its row locked'),
        );
        $carts->method('save')->willReturnCallback(
            function (): void {
                $this->session->log[] = 'saveCart';
            },
        );

        $orders = $this->createStub(OrderRepository::class);
        $orders->method('nextIdentity')->willReturnCallback(
            static fn(): OrderId => OrderId::fromString(Uuid::uuid4()->toString()),
        );
        $orders->method('save')->willReturnCallback(
            function (Order $order): void {
                $this->session->log[] = 'saveOrder';
                $this->savedOrders[] = $order;
            },
        );

        return new CheckoutCartCommandHandler($carts, $orders, $this->session, $this->clock, DomainEventPublisher::instance());
    }
}
