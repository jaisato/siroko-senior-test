<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\ReleaseExpiredCartsCommand;
use Siroko\Cart\Application\Command\Cart\ReleaseExpiredCartsCommandHandler;
use Siroko\Cart\Application\Service\CartCancellation;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\OrderRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Symfony\Component\Clock\MockClock;

/**
 * The expiry sweep. Candidates are listed without locks and re-checked under
 * the row lock one by one, so it is safe against a customer checking out at
 * the same moment and against a second sweep.
 */
final class ReleaseExpiredCartsCommandHandlerTest extends TestCase
{
    private const NOW = '2026-09-06 10:00:00';

    /** @var array<string, Cart> what ofIdForUpdate() answers, by id */
    private array $lockedCarts = [];

    /** @var list<CartId> what expiredPendingIds() answers */
    private array $candidates = [];

    /** @var list<array{0: string, 1: int}> stock credited, as (productId, units) */
    private array $returned = [];

    private RecordingSession $session;

    protected function setUp(): void
    {
        $this->lockedCarts = [];
        $this->candidates = [];
        $this->returned = [];
        $this->session = new RecordingSession();
    }

    public function test_it_cancels_every_expired_cart_and_returns_its_units(): void
    {
        $product = $this->product();
        $first = $this->expiredCart($product, 3);
        $second = $this->expiredCart($product, 1);

        $result = $this->handler()(new ReleaseExpiredCartsCommand(100));

        self::assertSame(2, $result->candidates);
        self::assertSame(2, $result->released);
        self::assertTrue($result->isShortOf(100));
        self::assertSame(CartStatus::CANCELED, $first->status()->toInt());
        self::assertSame(CartStatus::CANCELED, $second->status()->toInt());
        self::assertSame([[$product->id()->toString(), 3], [$product->id()->toString(), 1]], $this->returned);
    }

    /** One transaction per cart: a batch never holds every lock at once. */
    public function test_each_cart_is_released_in_its_own_transaction_under_its_lock(): void
    {
        $product = $this->product();
        $this->expiredCart($product, 1);
        $this->expiredCart($product, 1);

        $this->handler()(new ReleaseExpiredCartsCommand(100));

        self::assertSame(2, $this->session->transactions);
        self::assertSame(
            ['begin', 'lockCart', 'returnStock', 'saveCart', 'commit', 'begin', 'lockCart', 'returnStock', 'saveCart', 'commit'],
            $this->session->log,
        );
    }

    /**
     * Between the candidate list and the lock, a customer may have paid: the
     * locked cart is no longer pending and is left alone - and so is its stock.
     */
    public function test_a_candidate_that_was_paid_in_between_is_skipped(): void
    {
        $product = $this->product();
        $cart = $this->expiredCart($product, 2);
        $cart->pay();

        $result = $this->handler()(new ReleaseExpiredCartsCommand(100));

        self::assertSame(1, $result->candidates);
        self::assertSame(0, $result->released);
        self::assertSame(CartStatus::PAID, $cart->status()->toInt());
        self::assertSame([], $this->returned);
        self::assertNotContains('saveCart', $this->session->log);
    }

    /** A second sweep, or a cancellation by the customer, got there first. */
    public function test_a_candidate_already_released_is_skipped(): void
    {
        $product = $this->product();
        $cart = $this->expiredCart($product, 2);
        $cart->cancel();

        $result = $this->handler()(new ReleaseExpiredCartsCommand(100));

        self::assertSame(0, $result->released);
        self::assertSame([], $this->returned);
    }

    public function test_a_candidate_that_vanished_is_skipped(): void
    {
        $this->candidates[] = CartId::fromString(Uuid::uuid4()->toString());

        $result = $this->handler()(new ReleaseExpiredCartsCommand(100));

        self::assertSame(1, $result->candidates);
        self::assertSame(0, $result->released);
    }

    public function test_a_full_batch_is_not_short(): void
    {
        $product = $this->product();
        $this->expiredCart($product, 1);
        $this->expiredCart($product, 1);

        $result = $this->handler()(new ReleaseExpiredCartsCommand(2));

        self::assertFalse($result->isShortOf(2), 'there may be more; the caller asks again');
    }

    public function test_the_batch_size_is_bounded(): void
    {
        self::assertSame(ReleaseExpiredCartsCommand::DEFAULT_BATCH_SIZE, (new ReleaseExpiredCartsCommand())->batchSize);

        $this->expectException(\InvalidArgumentException::class);

        new ReleaseExpiredCartsCommand(0);
    }

    private function expiredCart(Product $product, int $units): Cart
    {
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending(), $now->modify('-1 hour'), $now->modify('-1 minute'));
        $cart->addItem(new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity($units)));

        $this->lockedCarts[$cart->id()->toString()] = $cart;
        $this->candidates[] = $cart->id();

        return $cart;
    }

    private function product(): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('SKU-' . random_int(1000, 9999)),
            Name::fromString('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity(50),
        );
    }

    private function handler(): ReleaseExpiredCartsCommandHandler
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('expiredPendingIds')->willReturnCallback(fn(\DateTimeImmutable $now, int $limit): array => \array_slice($this->candidates, 0, $limit));
        $carts->method('ofIdForUpdate')->willReturnCallback(function (CartId $id): ?Cart {
            $this->session->log[] = 'lockCart';

            return $this->lockedCarts[$id->toString()] ?? null;
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

        return new ReleaseExpiredCartsCommandHandler(
            $carts,
            new CartCancellation($carts, $products, $this->createStub(OrderRepository::class), new MockClock()),
            $this->session,
            new MockClock(self::NOW, 'UTC'),
        );
    }
}
