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
 * keeps is no more a migration's decision than what they are charged - and
 * preUp() says in SQL how to clear them, because at this point in the upgrade
 * no endpoint of either version can reach them; see REMEDY.
 */
final class Version20260906100000 extends AbstractMigration
{
    /**
     * What an operator can do about a cart either bound rejects, in SQL.
     *
     * It used to say "cancel them through the API (DELETE /v1/carts/{id})",
     * which is a door locked from both sides. That endpoint arrives with the
     * version being deployed, so the running application does not have it -
     * and the new one cannot serve it here either, because this migration is
     * what adds `cart_item.quantity`, which its own mapping requires: the
     * controller cannot load the cart until the migration it is meant to
     * unblock has run. So the only tool that reaches these rows is SQL, and
     * this says exactly which.
     *
     * What it does is what cancelling does: the units go back on the shelf and
     * the cart stops being pending. Both gates read `status = PENDING` only -
     * a cart nobody can check out any more cannot overflow an order total - so
     * a canceled cart clears them, and its lines stay, as the record of what
     * was in it, the same way the API's cancellation keeps them.
     *
     * The statements are one transaction, and the credit is conditional on the
     * cart still being pending - both, because either alone leaves a way to
     * give the same units back twice. The transaction is what stops a
     * connection dropped in the middle from committing half of it; the
     * condition is what makes the whole recipe safe to run again, which is the
     * one thing an operator whose COMMIT did not answer has to do. It also
     * settles the other race: a checkout landing first takes the cart out of
     * pending, and then the credit finds nothing to credit rather than putting
     * back units that have just been sold. The cart's row is locked first so
     * that decision cannot change under the two statements.
     *
     * The increment counts *rows*, because at this point in the series a line
     * is still one unit - `quantity` is the column this migration is about to
     * add.
     */
    private const REMEDY = 'Reconcile them by hand before running this again - the API cannot: '
        . 'DELETE /v1/carts/{id} ships with this version, and it cannot read these carts until this migration '
        . 'has added the column its mapping needs. Cancel each cart listed above in SQL instead, which puts its '
        . 'units back on the shelf and takes it out of pending (its lines stay, as the record of what was in '
        . "it), in one transaction - and safe to run again if a COMMIT does not answer:\n"
        . "  START TRANSACTION;\n"
        . "  -- No rows here means it is already done, or was never pending: stop and ROLLBACK.\n"
        . "  SELECT BIN_TO_UUID(id) FROM cart WHERE id = UUID_TO_BIN('<id>') AND status = "
        . CartStatus::PENDING . " FOR UPDATE;\n"
        . "  UPDATE product p\n"
        . "    JOIN (SELECT i.product_id, COUNT(*) AS units\n"
        . "            FROM cart_item i JOIN cart c ON c.id = i.cart_id\n"
        . "           WHERE i.cart_id = UUID_TO_BIN('<id>') AND c.status = " . CartStatus::PENDING . "\n"
        . "           GROUP BY i.product_id) held ON held.product_id = p.id\n"
        . "     SET p.quantity = p.quantity + held.units;\n"
        . '  UPDATE cart SET status = ' . CartStatus::CANCELED . " WHERE id = UUID_TO_BIN('<id>') AND status = "
        . CartStatus::PENDING . ";\n"
        . "  COMMIT;\n"
        . 'The credit repeats the pending condition on purpose: once the cart is out of pending - by this recipe '
        . 'or by a checkout that got in first - it credits nothing, so running the whole thing twice gives the '
        . "units back once.\n"
        . 'To keep a cart pending instead, delete only its surplus rows in that same transaction and add exactly '
        . 'that many units back to the product - deleting rows on their own leaves the stock short.';

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
