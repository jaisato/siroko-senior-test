<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository\DoctrineProductRepository;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * What a zero-row UPDATE means, which is not the same thing on every engine.
 *
 * MySQL's affected-row count is the number of rows it *changed*: a recount to
 * the figure the column already holds affects nothing. SQLite counts the rows
 * the statement touched and answers one. setStock() read zero as "no such
 * product" and the handler turned that into a 404, so confirming a count that
 * had not moved answered "product not found" on MySQL for a product sitting
 * right there - and the local SQLite suite could never show it.
 *
 * The connection is doubled here precisely so the branch is exercised on both:
 * the engine's counting is the input, not the thing under test.
 */
final class ProductStockRecountTest extends TestCase
{
    public function test_a_recount_that_changes_nothing_is_not_a_missing_product(): void
    {
        $repository = $this->repositoryOver(affectedRows: 0, rowIsThere: true);

        self::assertTrue($repository->setStock(self::anId(), new Quantity(7)));
    }

    public function test_a_recount_of_a_product_that_is_not_in_the_catalogue_still_fails(): void
    {
        $repository = $this->repositoryOver(affectedRows: 0, rowIsThere: false);

        self::assertFalse($repository->setStock(self::anId(), new Quantity(7)));
    }

    /** The ordinary case is untouched: one row changed, one recount done. */
    public function test_a_recount_that_moves_the_column_succeeds(): void
    {
        $repository = $this->repositoryOver(affectedRows: 1, rowIsThere: true);

        self::assertTrue($repository->setStock(self::anId(), new Quantity(7)));
    }

    private function repositoryOver(int $affectedRows, bool $rowIsThere): DoctrineProductRepository
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturn($affectedRows);
        // fetchOne() answers false when no row matches, as DBAL does.
        $connection->method('fetchOne')->willReturn($rowIsThere ? 1 : false);

        // Nothing of this product is loaded in the unit of work, so the
        // refresh that follows a successful recount has nothing to do.
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('tryGetById')->willReturn(false);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getUnitOfWork')->willReturn($unitOfWork);

        return new DoctrineProductRepository($em);
    }

    private static function anId(): ProductId
    {
        return ProductId::fromString(Uuid::uuid4()->toString());
    }
}
