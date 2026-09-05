<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\CustomerId;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The cart mapping: a line carries its quantity, and the database holds the
 * aggregate to one line per product. Runs on SQLite and MySQL alike.
 */
final class DoctrineCartRepositoryTest extends KernelTestCase
{
    private CartRepository $repository;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = static::getContainer()->get(CartRepository::class);
        $this->repository = $repository;

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;
    }

    public function test_a_cart_round_trips_with_the_quantity_of_each_line(): void
    {
        $product = $this->product();
        $cart = new Cart($this->repository->nextIdentity(), CartStatus::pending());
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(3));
        $this->repository->save($cart);

        $this->em->clear();
        $reloaded = $this->repository->ofId($cart->id());

        self::assertInstanceOf(Cart::class, $reloaded);
        self::assertCount(1, $reloaded->items());
        $line = $reloaded->items()->first();
        self::assertInstanceOf(CartItem::class, $line);
        self::assertSame(3, $line->quantity()->asInt());
        self::assertTrue($product->id()->equals($line->getProduct()->id()));
    }

    /**
     * The aggregate merges lines of one product; the unique index is what
     * makes that hold when two requests race past the merge.
     */
    public function test_the_database_refuses_a_second_line_for_the_same_product_in_a_cart(): void
    {
        $product = $this->product();
        $cart = new Cart($this->repository->nextIdentity(), CartStatus::pending());
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(1));
        $this->repository->save($cart);

        // Bypasses the aggregate on purpose: a line persisted on its own.
        $twin = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(1));
        $twin->setCart($cart);
        $this->em->persist($twin);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    public function test_the_same_product_may_be_in_two_different_carts(): void
    {
        $product = $this->product();

        foreach ([1, 2] as $_) {
            $cart = new Cart($this->repository->nextIdentity(), CartStatus::pending());
            $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(1));
            $this->repository->save($cart);
        }

        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(CartItem::class)->findAll());
    }

    public function test_timestamps_round_trip(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $cart = Cart::open($this->repository->nextIdentity(), $now, new \DateInterval('PT30M'));
        $this->repository->save($cart);

        $this->em->clear();
        $reloaded = $this->repository->ofId($cart->id());

        self::assertInstanceOf(Cart::class, $reloaded);
        self::assertSame('2026-09-06 10:00:00', $reloaded->createdAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-06 10:30:00', $reloaded->expiresAt()?->format('Y-m-d H:i:s'));
    }

    /** The sweep's candidates: pending, past their deadline, oldest deadline first, capped. */
    public function test_expired_pending_ids_lists_lapsed_pending_carts_oldest_first(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $product = $this->product();

        $lapsedLater = $this->cartExpiring($now->modify('-1 minute'), $product);
        $lapsedEarlier = $this->cartExpiring($now->modify('-1 hour'), $product);
        $onTheDot = $this->cartExpiring($now, $product);
        $this->cartExpiring($now->modify('+1 minute'), $product);
        $this->repository->save(new Cart($this->repository->nextIdentity(), CartStatus::pending()));

        $paid = $this->cartExpiring($now->modify('-1 hour'), $product);
        $paid->pay();
        $this->repository->save($paid);

        $ids = array_map(static fn(CartId $id): string => $id->toString(), $this->repository->expiredPendingIds($now, 10));

        self::assertSame([$lapsedEarlier->id()->toString(), $lapsedLater->id()->toString(), $onTheDot->id()->toString()], $ids);
        self::assertCount(2, $this->repository->expiredPendingIds($now, 2), 'the batch size caps the list');
        self::assertSame([], $this->repository->expiredPendingIds($now->modify('-2 hours'), 10));
    }

    public function test_the_owner_round_trips(): void
    {
        $cart = Cart::open($this->repository->nextIdentity(), new \DateTimeImmutable(), new \DateInterval('PT30M'), CustomerId::fromString('alice'));
        $this->repository->save($cart);

        $this->em->clear();

        self::assertSame('alice', $this->repository->ofId($cart->id())?->customerId()?->toString());
    }

    /** GET /v1/carts: a customer's carts newest first, or every cart; optionally one status. */
    public function test_search_lists_by_owner_and_status_newest_first(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $alice = CustomerId::fromString('alice');
        $product = $this->product();

        $old = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now->modify('-2 hours'), null, $alice);
        $recent = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now->modify('-1 hour'), null, $alice);
        $paid = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now, null, $alice);
        $paid->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(1));
        $paid->pay();
        $bobs = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now, null, CustomerId::fromString('bob'));
        $ownerless = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now);

        foreach ([$old, $recent, $paid, $bobs, $ownerless] as $cart) {
            $this->repository->save($cart);
        }

        $ids = static fn(array $carts): array => array_map(static fn(Cart $cart): string => $cart->id()->toString(), $carts);

        self::assertSame([$paid->id()->toString(), $recent->id()->toString(), $old->id()->toString()], $ids($this->repository->search($alice, null, 1, 10)));
        self::assertSame(3, $this->repository->countMatching($alice, null));
        self::assertSame([$recent->id()->toString(), $old->id()->toString()], $ids($this->repository->search($alice, CartStatus::pending(), 1, 10)));
        self::assertSame([$paid->id()->toString()], $ids($this->repository->search($alice, CartStatus::paid(), 1, 10)));
        self::assertSame([$old->id()->toString()], $ids($this->repository->search($alice, null, 3, 1)), 'pages follow the order');
        self::assertSame(5, $this->repository->countMatching(null, null), 'no owner: every cart');
        self::assertCount(5, $this->repository->search(null, null, 1, 10));
        self::assertSame(0, $this->repository->countMatching(CustomerId::fromString('carol'), null));
    }

    public function test_an_unknown_cart_is_null(): void
    {
        self::assertNull($this->repository->ofId($this->repository->nextIdentity()));
    }

    private function cartExpiring(\DateTimeImmutable $deadline, Product $product): Cart
    {
        $cart = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $deadline->modify('-30 minutes'), $deadline);
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(1));
        $this->repository->save($cart);

        return $cart;
    }

    private function product(): Product
    {
        $product = new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString(strtoupper(substr(Uuid::uuid4()->toString(), 0, 8))),
            Name::fromString('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity(50),
        );

        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
