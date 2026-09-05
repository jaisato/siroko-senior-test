<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A cart line carries a quantity; the pair (cart, product) names a line.
 *
 * Until now N units of a product were N rows of `cart_item`. Existing rows
 * are collapsed: the earliest row of each (cart, product) pair keeps the
 * count of units, the others go. The collapse cannot be undone by down() -
 * the individual row ids are gone - which is why it drops the column and the
 * constraint but leaves one row per pair.
 */
final class Version20260906100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cart_item.quantity, one line per (cart, product), duplicates collapsed by summing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart_item ADD quantity INT NOT NULL DEFAULT 1');

        // The earliest row of each pair (UUIDv7 ids sort by time) takes the count.
        $this->addSql(<<<'SQL'
            UPDATE cart_item ci
            JOIN (
                SELECT MIN(id) AS keep_id, COUNT(*) AS units
                FROM cart_item
                GROUP BY cart_id, product_id
                HAVING COUNT(*) > 1
            ) grouped ON grouped.keep_id = ci.id
            SET ci.quantity = grouped.units
            SQL);

        $this->addSql(<<<'SQL'
            DELETE ci FROM cart_item ci
            JOIN (
                SELECT MIN(id) AS keep_id, cart_id, product_id
                FROM cart_item
                GROUP BY cart_id, product_id
            ) grouped ON grouped.cart_id = ci.cart_id AND grouped.product_id = ci.product_id
            WHERE ci.id <> grouped.keep_id
            SQL);

        // The default only served the backfill; the application always writes a quantity.
        $this->addSql('ALTER TABLE cart_item ALTER quantity DROP DEFAULT');

        $this->addSql('CREATE UNIQUE INDEX uniq_cartitem_cart_product ON cart_item (cart_id, product_id)');
        // Its leading column is cart_id, so the single-column index is redundant
        // (and the foreign key on cart_id stays covered).
        $this->addSql('DROP INDEX idx_cartitem_cart ON cart_item');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_cartitem_cart ON cart_item (cart_id)');
        $this->addSql('DROP INDEX uniq_cartitem_cart_product ON cart_item');
        $this->addSql('ALTER TABLE cart_item DROP quantity');
    }
}
