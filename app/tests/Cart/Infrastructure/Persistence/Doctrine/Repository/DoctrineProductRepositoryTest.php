<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidStockAdjustmentException;
use Siroko\Cart\Domain\Repository\ProductCriteria;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The stock movements are raw SQL; this pins what they do to the rows and to
 * the entities already loaded in the request. Runs on SQLite and MySQL alike.
 */
final class DoctrineProductRepositoryTest extends KernelTestCase
{
    private ProductRepository $repository;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = static::getContainer()->get(ProductRepository::class);
        $this->repository = $repository;

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;
    }

    public function test_a_product_round_trips_with_its_price_value_object(): void
    {
        $product = $this->product('Gafas', 'K3', '129.95', 12);

        $this->em->clear();
        $reloaded = $this->repository->ofId($product->id());

        self::assertInstanceOf(Product::class, $reloaded);
        self::assertNotSame($product, $reloaded);
        self::assertTrue($product->id()->equals($reloaded->id()));
        self::assertSame('Gafas', $reloaded->name()->toString());
        self::assertSame('K3', $reloaded->code()->toString());
        self::assertSame(12, $reloaded->quantity()->asInt());
        self::assertTrue(Price::of('129.95', 'EUR')->equals($reloaded->price()));
        self::assertSame('129.95', $reloaded->price()->amount(), 'the column scale (4) does not leak into the amount');
        self::assertSame('EUR', $reloaded->price()->currency()->getCurrencyCode());
        self::assertSame('129.95 EUR', (string) $reloaded->price());
    }

    public function test_an_unknown_id_is_null(): void
    {
        self::assertNull($this->repository->ofId(ProductId::fromString(Uuid::uuid4()->toString())));
    }

    public function test_reserving_stock_takes_the_units_off_the_row_and_refreshes_the_loaded_entity(): void
    {
        $product = $this->product(stock: 5);

        self::assertTrue($this->repository->reserveStock($product->id(), 2));

        self::assertSame(3, $product->quantity()->asInt(), 'the managed instance sees the movement');
        self::assertSame(3, $this->stockInDatabase($product));
    }

    public function test_reserving_more_than_the_stock_changes_nothing(): void
    {
        $product = $this->product(stock: 1);

        self::assertFalse($this->repository->reserveStock($product->id(), 2));

        self::assertSame(1, $product->quantity()->asInt());
        self::assertSame(1, $this->stockInDatabase($product));
    }

    public function test_reserving_exactly_the_stock_leaves_zero(): void
    {
        $product = $this->product(stock: 2);

        self::assertTrue($this->repository->reserveStock($product->id(), 2));
        self::assertSame(0, $this->stockInDatabase($product));
        self::assertFalse($this->repository->reserveStock($product->id(), 1), 'nothing is left');
    }

    public function test_returning_stock_adds_the_units_back(): void
    {
        $product = $this->product(stock: 1);

        $this->repository->returnStock($product->id(), 3);

        self::assertSame(4, $product->quantity()->asInt());
        self::assertSame(4, $this->stockInDatabase($product));
    }

    /**
     * `quantity` is a signed INT and the sum happens in the database, so
     * `{"delta":1}` on a product already at the maximum used to leave MySQL to
     * refuse it - an out-of-range error the client read as a 500. The ceiling
     * belongs in the same UPDATE as the increment, like the floor of a
     * reservation, and what does not fit is said out loud rather than dropped.
     */
    public function test_stock_that_would_pass_the_maximum_is_refused_rather_than_overflowing(): void
    {
        $product = $this->product(stock: Quantity::MAX_QUANTITY);

        try {
            $this->repository->returnStock($product->id(), 1);
            self::fail('returning units past the maximum must be refused');
        } catch (InvalidStockAdjustmentException $refused) {
            self::assertStringContainsString((string) Quantity::MAX_QUANTITY, $refused->getMessage());
        }

        self::assertSame(Quantity::MAX_QUANTITY, $this->stockInDatabase($product), 'nothing was added');
    }

    public function test_stock_movements_on_an_unknown_product_touch_nothing(): void
    {
        $unknown = ProductId::fromString(Uuid::uuid4()->toString());

        self::assertFalse($this->repository->reserveStock($unknown, 1));
        $this->repository->returnStock($unknown, 1);

        self::assertNull($this->repository->ofId($unknown));
    }

    public function test_stock_movements_of_zero_or_negative_units_are_programming_errors(): void
    {
        $product = $this->product(stock: 5);

        $this->expectException(\InvalidArgumentException::class);

        // Deliberately violates the positive-int contract to exercise the guard.
        $this->repository->reserveStock($product->id(), 0); // @phpstan-ignore argument.type
    }

    public function test_returning_negative_units_is_a_programming_error(): void
    {
        $product = $this->product(stock: 5);

        $this->expectException(\InvalidArgumentException::class);

        // Deliberately violates the positive-int contract to exercise the guard.
        $this->repository->returnStock($product->id(), -1); // @phpstan-ignore argument.type
    }

    public function test_it_knows_whether_a_code_is_taken(): void
    {
        $this->product(code: 'TAKEN');

        self::assertTrue($this->repository->existsWithCode(ProductCode::fromString('TAKEN')));
        self::assertFalse($this->repository->existsWithCode(ProductCode::fromString('FREE')));
    }

    /** The database enforces what the handler checks, so a lost race cannot create a twin. */
    public function test_the_code_is_unique_at_the_database_level(): void
    {
        $this->product(code: 'TWIN');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->product(code: 'TWIN');
    }

    public function test_find_all_pages_by_name_and_count_all_reports_the_total(): void
    {
        foreach (['Cherry', 'Apple', 'Banana'] as $name) {
            $this->product($name);
        }

        self::assertSame(3, $this->repository->countAll());
        self::assertSame(['Apple', 'Banana'], self::names($this->repository->findAll(1, 2)));
        self::assertSame(['Cherry'], self::names($this->repository->findAll(2, 2)));
        self::assertSame([], $this->repository->findAll(3, 2));
    }

    public function test_an_empty_catalogue_counts_zero(): void
    {
        self::assertSame(0, $this->repository->countAll());
        self::assertSame([], $this->repository->findAll(1, 20));
    }

    public function test_a_product_is_found_by_its_code(): void
    {
        $product = $this->product(code: 'K3');

        self::assertTrue($product->id()->equals($this->repository->ofCode(ProductCode::fromString('K3'))?->id() ?? ProductId::fromString(Uuid::uuid4()->toString())));
        self::assertNull($this->repository->ofCode(ProductCode::fromString('NOPE')));
    }

    public function test_a_product_can_be_loaded_with_its_row_locked(): void
    {
        $product = $this->product();

        $locked = $this->em->wrapInTransaction(fn() => $this->repository->ofIdForUpdate($product->id()));

        self::assertInstanceOf(Product::class, $locked);
        self::assertTrue($product->id()->equals($locked->id()));
    }

    /** A withdrawn product keeps its row but is invisible to every catalogue read. */
    public function test_a_withdrawn_product_is_not_found_by_id_code_or_listing_but_its_row_stays(): void
    {
        $product = $this->product('Gone', code: 'GONE');
        $product->delete(new \DateTimeImmutable());
        $this->repository->save($product);
        $this->em->clear();

        self::assertNull($this->repository->ofId($product->id()));
        self::assertNull($this->repository->ofCode(ProductCode::fromString('GONE')));
        self::assertSame(0, $this->repository->countAll());
        self::assertSame([], $this->repository->findAll(1, 10));
        self::assertTrue($this->repository->existsWithCode(ProductCode::fromString('GONE')), 'the code stays taken');
        self::assertFalse($this->repository->setStock($product->id(), new Quantity(5)), 'no recount for a withdrawn product');

        $row = $this->em->find(Product::class, $product->id());
        self::assertInstanceOf(Product::class, $row);
        self::assertTrue($row->isDeleted());
    }

    public function test_exists_with_code_can_leave_one_product_out(): void
    {
        $product = $this->product(code: 'MINE');

        self::assertTrue($this->repository->existsWithCode(ProductCode::fromString('MINE')));
        self::assertFalse($this->repository->existsWithCode(ProductCode::fromString('MINE'), $product->id()), 'its own code is not a clash');
        self::assertTrue($this->repository->existsWithCode(ProductCode::fromString('MINE'), ProductId::fromString(Uuid::uuid4()->toString())));
    }

    public function test_set_stock_replaces_the_available_units_and_refreshes_the_entity(): void
    {
        $product = $this->product(stock: 5);

        self::assertTrue($this->repository->setStock($product->id(), new Quantity(42)));

        self::assertSame(42, $product->quantity()->asInt());
        self::assertSame(42, $this->stockInDatabase($product));
        self::assertFalse($this->repository->setStock(ProductId::fromString(Uuid::uuid4()->toString()), new Quantity(1)));
    }

    /**
     * Confirming a count that has not moved is the most ordinary recount there
     * is, and on MySQL it changes no row - which setStock() read as "no such
     * product" and the API answered 404. SQLite counts matched rows, so only
     * the MySQL run of this can fail; ProductStockRecountTest pins the branch
     * itself, over a doubled connection, on any engine.
     */
    public function test_set_stock_to_the_figure_already_stored_is_still_a_recount(): void
    {
        $product = $this->product(stock: 7);

        self::assertTrue($this->repository->setStock($product->id(), new Quantity(7)));

        self::assertSame(7, $this->stockInDatabase($product));
    }

    /**
     * The text filter matches the name *or* the code, and that alternative has
     * to stay one predicate: read as `name LIKE … OR (code LIKE … AND price >=
     * … AND quantity > 0)` it would return every product whose name matches,
     * ignoring the other filters entirely. Doctrine parenthesises a part
     * holding " OR " when it composes the WHERE (DDC-1237), so the query is
     * right - this pins it, because nothing else here would notice if a
     * refactor moved the alternative somewhere that does not.
     */
    public function test_the_text_filter_is_one_predicate_next_to_the_other_filters(): void
    {
        $this->product('Gafas de sol', 'K3', '129.95', 5);
        $this->product('Casco', 'GAFAS-X', '10.00', 0);

        $found = $this->repository->search(ProductCriteria::of(text: 'gafas', inStock: true), 1, 10);

        self::assertSame(['Gafas de sol'], array_map(
            static fn(Product $p): string => $p->name()->toString(),
            $found,
        ), 'the out-of-stock product whose code matches is filtered out too');
    }

    public function test_search_filters_by_text_price_and_stock_and_sorts(): void
    {
        $this->product('Gafas de sol', 'K3', '129.95', 3);
        $this->product('Funda de gafas', 'F1', '9.99', 0);
        $this->product('Casco', 'H1', '59.00', 8);

        self::assertSame(['Funda de gafas', 'Gafas de sol'], self::names($this->repository->search(ProductCriteria::of('GAFAS'), 1, 10)));
        self::assertSame(2, $this->repository->countMatching(ProductCriteria::of('gafas')));
        self::assertSame(['Gafas de sol'], self::names($this->repository->search(ProductCriteria::of('k3'), 1, 10)), 'the code is searched too');
        self::assertSame(['Casco', 'Gafas de sol'], self::names($this->repository->search(ProductCriteria::of(minPrice: '10'), 1, 10)));
        self::assertSame(['Funda de gafas'], self::names($this->repository->search(ProductCriteria::of(maxPrice: '9.99'), 1, 10)), 'bounds are inclusive');
        self::assertSame(['Casco', 'Gafas de sol'], self::names($this->repository->search(ProductCriteria::of(inStock: true), 1, 10)));
        self::assertSame(['Funda de gafas'], self::names($this->repository->search(ProductCriteria::of(inStock: false), 1, 10)));
        self::assertSame(['Gafas de sol', 'Casco', 'Funda de gafas'], self::names($this->repository->search(ProductCriteria::of(sort: '-price'), 1, 10)));
        self::assertSame(['Funda de gafas', 'Casco', 'Gafas de sol'], self::names($this->repository->search(ProductCriteria::of(sort: 'price'), 1, 10)));
        self::assertSame(['Funda de gafas', 'Casco', 'Gafas de sol'], self::names($this->repository->search(ProductCriteria::of(sort: 'code'), 1, 10)));
        self::assertSame(['Gafas de sol', 'Funda de gafas'], self::names($this->repository->search(ProductCriteria::of(sort: '-name', minPrice: '1', maxPrice: '200', inStock: null, text: 'gafas'), 1, 10)), 'filters combine');
        self::assertSame(['Casco'], self::names($this->repository->search(ProductCriteria::of(sort: '-price'), 2, 1)), 'pages follow the order');
    }

    /** `%` and `_` in the search text are escaped, and the escape character is declared. */
    public function test_search_treats_like_wildcards_as_literal_text(): void
    {
        $this->product('100% cotton', 'C1');
        $this->product('100 cotton', 'C2');
        $this->product('a_b', 'U1');
        $this->product('axb', 'U2');
        $this->product('bang!', 'E1');

        self::assertSame(['100% cotton'], self::names($this->repository->search(ProductCriteria::of('100%'), 1, 10)));
        self::assertSame(['a_b'], self::names($this->repository->search(ProductCriteria::of('a_b'), 1, 10)));
        self::assertSame(['bang!'], self::names($this->repository->search(ProductCriteria::of('g!'), 1, 10)), 'the escape character itself is escaped');
    }

    public function test_next_identity_is_a_fresh_uuid(): void
    {
        $first = $this->repository->nextIdentity();
        $second = $this->repository->nextIdentity();

        self::assertTrue(Uuid::isValid($first->toString()));
        self::assertFalse($first->equals($second));
    }

    private function product(string $name = 'A product', ?string $code = null, string $amount = '10.00', int $stock = 5): Product
    {
        $product = new Product(
            $this->repository->nextIdentity(),
            ProductCode::fromString($code ?? strtoupper(substr(Uuid::uuid4()->toString(), 0, 8))),
            Name::fromString($name),
            Price::of($amount, 'EUR'),
            new Quantity($stock),
        );

        $this->repository->save($product);

        return $product;
    }

    private function stockInDatabase(Product $product): int
    {
        $quantity = $this->em->getConnection()->fetchOne(
            'SELECT quantity FROM product WHERE id = :id',
            ['id' => $product->id()],
            ['id' => 'product_id'],
        );

        return (int) $quantity;
    }

    /**
     * @param list<Product> $products
     *
     * @return list<string>
     */
    private static function names(array $products): array
    {
        return array_map(static fn(Product $product): string => $product->name()->toString(), $products);
    }
}
