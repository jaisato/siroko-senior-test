<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\ValueObject\CartStatus;

/**
 * A cart line carries a quantity; the pair (cart, product) names a line.
 *
 * Until now N units of a product were N rows of `cart_item`. Existing rows
 * are collapsed: the earliest row of each (cart, product) pair keeps the
 * count of units, the others go. The collapse cannot be undone by down() -
 * the individual row ids are gone - which is why it drops the column and the
 * constraint but leaves one row per pair.
 *
 * A line may hold at most CartItem::MAX_QUANTITY units and a cart at most
 * Cart::MAX_LINES lines, and Price::MAX_AMOUNT is computed from the two: a
 * pending cart whose rows collapse past either bound is a cart that can still
 * be checked out into an order total the money columns do not fit. Those carts
 * stop the migration instead of being rewritten by it - how many units somebody
 * keeps is no more a migration's decision than what they are charged.
 */
final class Version20260906100000 extends AbstractMigration
{
    /** What an operator can do about a cart either bound rejects. */
    private const REMEDY = 'Cancel them through the API (DELETE /v1/carts/{id}), which puts the units back on '
        . 'the shelf - deleting the rows by hand leaves the stock short - then run this migration again.';

    public function getDescription(): string
    {
        return 'cart_item.quantity, one line per (cart, product), duplicates collapsed by summing';
    }

    /**
     * Before any DDL, so a cart that cannot satisfy either bound is left
     * exactly as it was.
     *
     * Both are checked, because Price::MAX_AMOUNT is computed from the two
     * together: a cart of MAX_LINES lines each holding MAX_QUANTITY units at
     * the highest unit price is the dearest total the money columns must fit.
     * Gating only the units let 101 products of 100 rows each through, and
     * that cart overflows `orders.total_amount` at checkout exactly like a
     * single line of 150 would.
     */
    public function preUp(Schema $schema): void
    {
        // BIN_TO_UUID, because cart.id is BINARY(16): concatenated raw, the id
        // an operator is asked to act on comes out as sixteen bytes of noise.
        $overUnits = $this->pendingCartsWhere(
            <<<'SQL'
                SELECT CONCAT(BIN_TO_UUID(i.cart_id), ' (', COUNT(*), ' units of ', p.code, ')')
                  FROM cart_item i
                  JOIN cart c ON c.id = i.cart_id
                  JOIN product p ON p.id = i.product_id
                 WHERE c.status = :pending
                 GROUP BY i.cart_id, i.product_id, p.code
                HAVING COUNT(*) > :limit
                 ORDER BY COUNT(*) DESC
                 LIMIT 10
                SQL,
            CartItem::MAX_QUANTITY,
        );

        $this->abortIf(
            [] !== $overUnits,
            \sprintf(
                'These pending carts hold more units of one product than a line may carry (%d), so collapsing '
                . 'their rows would leave a line the domain refuses and a total the money columns do not fit: %s. %s',
                CartItem::MAX_QUANTITY,
                implode(', ', $overUnits),
                self::REMEDY,
            ),
        );

        $overLines = $this->pendingCartsWhere(
            <<<'SQL'
                SELECT CONCAT(BIN_TO_UUID(i.cart_id), ' (', COUNT(DISTINCT i.product_id), ' products)')
                  FROM cart_item i
                  JOIN cart c ON c.id = i.cart_id
                 WHERE c.status = :pending
                 GROUP BY i.cart_id
                HAVING COUNT(DISTINCT i.product_id) > :limit
                 ORDER BY COUNT(DISTINCT i.product_id) DESC
                 LIMIT 10
                SQL,
            Cart::MAX_LINES,
        );

        $this->abortIf(
            [] !== $overLines,
            \sprintf(
                'These pending carts hold more distinct products than a cart may have lines (%d), which the '
                . 'collapse turns into that many lines and a total the money columns do not fit: %s. %s',
                Cart::MAX_LINES,
                implode(', ', $overLines),
                self::REMEDY,
            ),
        );
    }

    /**
     * The pending carts a bound rejects, at most ten, already described.
     *
     * Only carts that can still be checked out: a paid, delivered or canceled
     * cart has whatever total it was settled at, stored on its order, and
     * nothing will add to its lines again.
     *
     * @return list<string>
     */
    private function pendingCartsWhere(string $sql, int $limit): array
    {
        /** @var list<string> $rows */
        $rows = $this->connection->fetchFirstColumn(
            $sql,
            ['pending' => CartStatus::PENDING, 'limit' => $limit],
        );

        return array_map(strval(...), $rows);
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
