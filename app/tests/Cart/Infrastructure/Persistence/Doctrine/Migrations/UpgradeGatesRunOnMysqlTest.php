<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations\Version20260905120000;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations\Version20260906100000;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations\Version20260906180000;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations\Version20260906190000;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The upgrade gates read the database before any DDL runs, and
 * UpgradeGatesTest drives them through a stubbed connection: it can check the
 * question each one asks, and cannot check that MySQL will answer it.
 *
 * It did not. `SELECT code ... GROUP BY code COLLATE utf8mb4_bin` is rejected
 * under only_full_group_by - the collated expression is not the bare column, so
 * the bare column is non-aggregated - and the migration step failed on the
 * first CI run after the assertion about its text passed. This executes each
 * query against the real engine, which is the only place that answer exists.
 *
 * The tables are empty (the suite owns the database), so nothing aborts: what
 * is under test is that the statement runs at all.
 */
#[Group('mysql')]
final class UpgradeGatesRunOnMysqlTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{0: class-string<AbstractMigration>}>
     */
    public static function gates(): iterable
    {
        yield 'product.code is unique, compared byte for byte' => [Version20260905120000::class];
        yield 'no pending cart past the line limit' => [Version20260906100000::class];
        yield 'no cart in two currencies' => [Version20260906180000::class];
        yield 'nothing on sale above the price ceiling' => [Version20260906190000::class];
    }

    /**
     * @param class-string<AbstractMigration> $migration
     */
    #[DataProvider('gates')]
    public function test_the_gate_query_is_one_mysql_accepts(string $migration): void
    {
        self::bootKernel();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();

        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::fail('The migrations are MySQL DDL; run this with --group mysql against a MySQL DATABASE_URL.');
        }

        // A statement MySQL rejects throws out of here; the empty tables give
        // an accepted one nothing to abort over. Either way the answer comes
        // from the engine, which is the whole point of this test.
        (new $migration($connection, new NullLogger()))->preUp(new Schema());

        self::assertSame([], $this->warnings($connection), 'the statement ran cleanly');
    }

    /**
     * What MySQL has to say about the statement it just ran. Empty is the
     * healthy answer, and it is a real assertion rather than assertTrue(true):
     * a query that parses but warns (a truncated argument to BIN_TO_UUID, say)
     * is not one this migration should be shipping.
     *
     * @return list<string>
     */
    private function warnings(Connection $connection): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $connection->fetchAllAssociative('SHOW WARNINGS');

        return array_map(
            static fn(array $row): string => \sprintf('%s: %s', $row['Code'] ?? '?', $row['Message'] ?? ''),
            $rows,
        );
    }
}
