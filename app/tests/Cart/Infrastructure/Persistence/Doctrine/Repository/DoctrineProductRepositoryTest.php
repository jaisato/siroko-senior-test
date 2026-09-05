<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Product;
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
