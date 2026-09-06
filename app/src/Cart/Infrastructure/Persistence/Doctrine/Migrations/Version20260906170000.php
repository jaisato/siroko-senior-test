<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Customer identifiers compare the way the value object says they do.
 *
 * CustomerId is an opaque printable-ASCII string handed over by whatever
 * authenticates the caller, and `equals()` compares it byte for byte: "alice"
 * and "Alice" are two customers. The columns holding it inherited the
 * database's default collation, which on MySQL is case-insensitive, so
 * `WHERE customer_id = 'alice'` matched both - and `GET /v1/carts` handed one
 * customer the other's cart ids, contents and totals. Nothing in the domain
 * was wrong; the comparison happened one layer below it, under different
 * rules.
 *
 * utf8mb4_bin makes the column compare like the value object. The index on
 * (customer_id, created_at) is rebuilt by MySQL as part of the MODIFY, so the
 * listing keeps using it.
 *
 * The mapping declares the collation too, and has to: Doctrine puts the
 * connection's default collation on every column of the mapping side, so a
 * column left undeclared reads as utf8mb4_unicode_ci there and
 * `doctrine:schema:validate` calls the database out of sync.
 *
 * SQLite, which the local test profile uses, already compares TEXT byte for
 * byte - it never had the bug - but it does not know the name and would refuse
 * the CREATE TABLE. SqliteBinaryCollationMiddleware registers one that behaves
 * the same, in the test environment only.
 */
final class Version20260906170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cart.customer_id / orders.customer_id compare case-sensitively, like CustomerId does';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart MODIFY customer_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL');
        $this->addSql('ALTER TABLE orders MODIFY customer_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Back to the table's default collation, which is where these columns
        // came from and what every other VARCHAR here still uses.
        $this->addSql('ALTER TABLE cart MODIFY customer_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders MODIFY customer_id VARCHAR(64) DEFAULT NULL');
    }
}
