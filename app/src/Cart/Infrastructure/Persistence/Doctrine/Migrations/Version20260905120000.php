<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A product code identifies exactly one product: unique index on product.code.';
    }

    public function up(Schema $schema): void
    {
        // A database that already holds two products under one code cannot get
        // this index, and MySQL says so with a "Duplicate entry" naming a
        // single row - no help at all in deciding which of the two is the
        // product. Nor is picking one here: a code identifies a product, and
        // renaming somebody's catalogue behind their back is not a migration.
        // So the offending codes are named and the deploy stops.
        /** @var list<string> $duplicates */
        $duplicates = $this->connection->fetchFirstColumn(
            'SELECT code FROM product GROUP BY code HAVING COUNT(*) > 1 ORDER BY code',
        );

        $this->abortIf([] !== $duplicates, \sprintf(
            'product.code is not unique yet: %s%s. Decide which row keeps each code (a withdrawn product keeps its own) and re-run.',
            implode(', ', \array_slice($duplicates, 0, 10)),
            \count($duplicates) > 10 ? \sprintf(' and %d more', \count($duplicates) - 10) : '',
        ));

        $this->addSql('CREATE UNIQUE INDEX uniq_product_code ON product (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_product_code ON product');
    }
}
