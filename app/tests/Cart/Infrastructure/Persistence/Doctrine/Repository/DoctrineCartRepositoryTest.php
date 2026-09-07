<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
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
use PHPUnit\Framework\Attributes\Group;
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

        foreach ([$old, $recent, $paid, $bobs] as $cart) {
            $this->repository->save($cart);
        }

        $ids = static fn(array $carts): array => array_map(static fn(Cart $cart): string => $cart->id()->toString(), $carts);

        self::assertSame([$paid->id()->toString(), $recent->id()->toString(), $old->id()->toString()], $ids($this->repository->search($alice, null, 1, 10)));
        self::assertSame(3, $this->repository->countMatching($alice, null));
        self::assertSame([$recent->id()->toString(), $old->id()->toString()], $ids($this->repository->search($alice, CartStatus::pending(), 1, 10)));
        self::assertSame([$paid->id()->toString()], $ids($this->repository->search($alice, CartStatus::paid(), 1, 10)));
        self::assertSame([$old->id()->toString()], $ids($this->repository->search($alice, null, 3, 1)), 'pages follow the order');
        self::assertSame(4, $this->repository->countMatching(null, null), 'no owner: every cart');
        self::assertCount(4, $this->repository->search(null, null, 1, 10));
        self::assertSame(0, $this->repository->countMatching(CustomerId::fromString('carol'), null));
    }

    /**
     * A cart opened before API_TOKENS was set has no owner, and
     * Cart::isAccessibleBy() hands it to whoever asks: GET, add, checkout all
     * work on it. The listing filtered on equality alone, so those carts were
     * in none of them and in no total either - the one place a client finds out
     * which carts it may use did not name the carts the API lets it use.
     */
    public function test_a_cart_with_no_owner_is_listed_for_every_caller(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $alice = CustomerId::fromString('alice');

        $hers = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now->modify('-1 hour'), null, $alice);
        $legacy = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now);
        $bobs = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now->modify('-2 hours'), null, CustomerId::fromString('bob'));

        foreach ([$hers, $legacy, $bobs] as $cart) {
            $this->repository->save($cart);
        }

        $ids = static fn(array $carts): array => array_map(static fn(Cart $cart): string => $cart->id()->toString(), $carts);

        self::assertSame(
            [$legacy->id()->toString(), $hers->id()->toString()],
            $ids($this->repository->search($alice, null, 1, 10)),
            "the ownerless cart is hers to use, and bob's is not hers to see",
        );
        self::assertSame(2, $this->repository->countMatching($alice, null), 'and the total counts it too');
        // Ownerless means nobody's, not "the first caller's": carol owns no
        // cart at all and still gets the one the API would let her check out.
        self::assertSame([$legacy->id()->toString()], $ids($this->repository->search(CustomerId::fromString('carol'), null, 1, 10)));
        // The status filter still narrows within that set.
        self::assertSame(2, $this->repository->countMatching($alice, CartStatus::pending()));
        self::assertSame(0, $this->repository->countMatching($alice, CartStatus::paid()));
    }

    /**
     * CustomerId compares byte for byte, so "alice" and "Alice" are two
     * customers. The column inherited MySQL's case-insensitive default
     * collation and matched both, and the listing handed one customer the
     * other's cart ids, contents and totals.
     *
     * MySQL only: SQLite compares TEXT byte for byte already and would pass
     * whatever the column says, so it cannot tell the two apart.
     */
    #[Group('mysql')]
    public function test_owners_whose_ids_differ_only_in_case_are_different_customers(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $lower = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now, null, CustomerId::fromString('alice'));
        $upper = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now, null, CustomerId::fromString('Alice'));

        foreach ([$lower, $upper] as $cart) {
            $this->repository->save($cart);
        }

        $found = $this->repository->search(CustomerId::fromString('alice'), null, 1, 10);

        self::assertSame([$lower->id()->toString()], array_map(static fn(Cart $cart): string => $cart->id()->toString(), $found));
        self::assertSame(1, $this->repository->countMatching(CustomerId::fromString('alice'), null));
        self::assertSame(1, $this->repository->countMatching(CustomerId::fromString('Alice'), null));
    }

    public function test_an_unknown_cart_is_null(): void
    {
        self::assertNull($this->repository->ofId($this->repository->nextIdentity()));
    }

    /**
     * A page arrives with its lines and products already loaded.
     *
     * `CartRead::fromModel()` walks every line of every cart and every line's
     * product. With only the carts loaded, that walk is where the queries
     * happen - one per cart for the EXTRA_LAZY collection, one per product for
     * the LAZY association - so a full page of a hundred carts with fifty
     * products each was thousands of round trips and timed out. Nothing about
     * the answer was wrong, which is why only this can catch it.
     *
     * Asserted on the hydration state rather than on a query count because the
     * suite runs under dama/doctrine-test-bundle, whose static connection is
     * not the one Doctrine's profiling middleware wraps: no counter here sees
     * anything. An initialised collection and a loaded product are the property
     * the count would be evidence for - neither can issue a query when the
     * reading touches it. `isInitialized()` is read before the collection is
     * iterated, because iterating is what would load it.
     */
    public function test_a_page_of_carts_arrives_with_its_lines_and_products_loaded(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));

        for ($i = 0; $i < 3; ++$i) {
            $cart = new Cart($this->repository->nextIdentity(), CartStatus::pending(), $now->modify("-{$i} hours"));
            $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $this->product(), new Quantity(1));
            $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $this->product(), new Quantity(2));
            $this->repository->save($cart);
        }

        $this->em->clear();

        $carts = $this->repository->search(null, null, 1, 10);
        $unitOfWork = $this->em->getUnitOfWork();

        $lines = 0;
        foreach ($carts as $cart) {
            $items = $cart->items();
            self::assertInstanceOf(PersistentCollection::class, $items);
            self::assertTrue($items->isInitialized(), 'the lines came with the page');

            foreach ($items as $line) {
                self::assertFalse($unitOfWork->isUninitializedObject($line->getProduct()), 'and so did their products');
                ++$lines;
            }
        }

        self::assertCount(3, $carts);
        self::assertSame(6, $lines);
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
