<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A product code identifies exactly one product, compared the way the domain
 * compares it.
 *
 * `product.code` inherited the table's utf8mb4_unicode_ci, which compares
 * case-insensitively, while ProductCode::equals() compares byte for byte. So
 * `uniq_product_code` would refuse `abc` next to `ABC` - a valid create
 * answering 409 - and the by-code lookup could hand back `ABC` for a request
 * for `abc`. SQLite compares TEXT byte for byte, so the local suite and MySQL
 * would disagree about the same data. utf8mb4_bin makes the column compare the
 * way the value object does; SqliteBinaryCollationMiddleware teaches SQLite the
 * name.
 *
 * The collation goes on before the index, not after it: applied afterwards, an
 * upgraded catalogue holding both `ABC` and `abc` - two products the final
 * schema is meant to allow - was grouped as a duplicate by the check below and
 * refused the index, and the deploy stopped over data that is not in conflict.
 * Which is also why the check itself runs in preUp() with the collation spelled
 * out: up() only queues its SQL, so a check inside it would still see the old
 * collation.
 *
 * A second check asks the other half of the same question: a code identifies a
 * product only if the API can be asked for it, and a legacy code holding a
 * slash names no route under `GET /v1/products/by-code/{code}`.
 */
final class Version20260905120000 extends AbstractMigration
{
    /** How the domain compares a code, and how the column is about to. */
    private const BINARY = 'utf8mb4_bin';

    public function getDescription(): string
    {
        return 'A product code identifies exactly one product: case-sensitive product.code, unique index on it.';
    }

    /**
     * Before any DDL, so a catalogue that cannot satisfy the new rule is left
     * exactly as it was.
     */
    public function preUp(Schema $schema): void
    {
        // A database that already holds two products under one code cannot get
        // this index, and MySQL says so with a "Duplicate entry" naming a
        // single row - no help at all in deciding which of the two is the
        // product. Nor is picking one here: a code identifies a product, and
        // renaming somebody's catalogue behind their back is not a migration.
        // So the offending codes are named and the deploy stops.
        // MIN(code), not code: `code COLLATE utf8mb4_bin` is a different
        // expression from `code` as far as MySQL is concerned, so selecting the
        // bare column beside that GROUP BY is a non-aggregated column under
        // only_full_group_by and the query is rejected (error 1055). Every row
        // of a group grouped that way is byte-identical, so the minimum of the
        // group is the code.
        /** @var list<string> $duplicates */
        $duplicates = $this->connection->fetchFirstColumn(
            \sprintf(
                'SELECT MIN(code) FROM product GROUP BY code COLLATE %s HAVING COUNT(*) > 1 ORDER BY MIN(code)',
                self::BINARY,
            ),
        );

        $this->abortIf([] !== $duplicates, \sprintf(
            'product.code is not unique yet: %s%s. Decide which row keeps each code (a withdrawn product keeps its own) and re-run.',
            implode(', ', \array_slice($duplicates, 0, 10)),
            \count($duplicates) > 10 ? \sprintf(' and %d more', \count($duplicates) - 10) : '',
        ));

        // The other half of "a code identifies a product": it has to be one the
        // API can be asked for. ProductCode refuses a slash or a control
        // character for exactly that reason - `GET /v1/products/by-code/{code}`
        // reads a slash as another path segment, so `ABC/123` names no route -
        // but hydration does not re-apply the rule, so a row written before it
        // existed keeps a code the lookup this series introduces cannot reach.
        // Renaming it here is the same overreach as picking a winner above.
        /** @var list<string> $unaddressable */
        $unaddressable = $this->connection->fetchFirstColumn(
            "SELECT code FROM product WHERE code REGEXP '[/[:cntrl:]]' ORDER BY code LIMIT 11",
        );

        $this->abortIf([] !== $unaddressable, \sprintf(
            'These product codes cannot be addressed by GET /v1/products/by-code/{code}, which reads a slash as '
            . 'another path segment and refuses a control character: %s%s. Rename them (PATCH /v1/products/{id}) '
            . 'and re-run.',
            implode(', ', \array_slice($unaddressable, 0, 10)),
            \count($unaddressable) > 10 ? ' and more' : '',
        ));
    }

    public function up(Schema $schema): void
    {
        $this->addSql(\sprintf(
            'ALTER TABLE product MODIFY code VARCHAR(50) CHARACTER SET utf8mb4 COLLATE %s NOT NULL COMMENT \'(DC2Type:product_code)\'',
            self::BINARY,
        ));

        $this->addSql('CREATE UNIQUE INDEX uniq_product_code ON product (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_product_code ON product');

        // Back to the table's default collation, which is where the column came
        // from and what every other VARCHAR here still uses.
        $this->addSql('ALTER TABLE product MODIFY code VARCHAR(50) NOT NULL COMMENT \'(DC2Type:product_code)\'');
    }
}
