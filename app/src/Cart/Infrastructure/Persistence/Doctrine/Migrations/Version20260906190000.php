<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\Price;

/**
 * A bound the database never enforced, and a fact it had nowhere to record.
 *
 * `Price::MAX_AMOUNT` bounds a unit price so that a full cart of it still fits
 * the money columns. Nothing enforced it on rows written before that bound
 * existed, and hydration does not re-apply it, so a legacy product priced above
 * it stayed sellable and its order total overflowed `orders.total_amount` at
 * checkout - a 500 for a cart that is perfectly valid.
 *
 * Such a row stops this migration rather than being rewritten by it: the price
 * a customer is charged is not something a migration may decide. Whoever runs
 * it reprices those products, or withdraws them, and runs it again.
 *
 * Withdrawing settles the future but not the present: a pending cart keeps the
 * units it already holds, and checkout does not re-ask the catalogue. So a
 * second gate asks the arithmetic directly - whether any pending cart, as it
 * stands, already totals more than `orders.total_amount` can hold - which is
 * the only thing a cart that can no longer grow can be judged on.
 */
final class Version20260906190000 extends AbstractMigration
{
    /**
     * The largest figure `orders.total_amount` can hold: NUMERIC(19, 4), so
     * fifteen integral digits. Price::MAX_AMOUNT is derived from it - the
     * dearest unit price a full cart of which still fits here - and this is
     * the bound itself, which is what a cart that cannot grow is measured
     * against.
     */
    private const COLUMN_MAXIMUM = '999999999999999.9999';

    public function getDescription(): string
    {
        return 'orders can be cancelled, and legacy prices above the domain maximum stop the upgrade';
    }

    /**
     * Before any DDL, so a database that cannot satisfy the new rule is left
     * exactly as it was.
     */
    public function preUp(Schema $schema): void
    {
        // Only what is still on sale. The ceiling is about what a cart *could*
        // become: a product on sale can be added up to Cart::MAX_LINES lines of
        // CartItem::MAX_QUANTITY units, and Price::MAX_AMOUNT is the unit price
        // at which that cart still fits the money columns. A withdrawn product
        // has no such future - reserveStock refuses one, so neither an add nor
        // a quantity change can put another unit of it in a cart - and stopping
        // the deploy over it would be asking for a fix (withdraw it) that has
        // already been applied.
        $overpriced = $this->connection->fetchFirstColumn(
            'SELECT code FROM product WHERE price_amount > :maximum AND deleted_at IS NULL ORDER BY code LIMIT 10',
            ['maximum' => Price::MAX_AMOUNT],
        );

        $this->abortIf(
            [] !== $overpriced,
            \sprintf(
                'These products are priced above the maximum a unit price may hold (%s), so a full cart of one '
                . 'would overflow the order total: %s. Reprice or withdraw them, then run this migration again.',
                Price::MAX_AMOUNT,
                implode(', ', array_map(strval(...), $overpriced)),
            ),
        );

        // What a withdrawn product leaves behind is a cart of a fixed size, so
        // the question it raises is not the ceiling but the arithmetic: does
        // *this* cart, as it stands and as it can no longer grow, check out
        // into a total the column holds? Asking only whether an overpriced
        // withdrawn product was present blocked the deploy over carts that are
        // perfectly payable - one unit at a price the column fits is not an
        // overflow, and no unit can be added to it.
        //
        // Per currency as well as per cart: an order carries one total in one
        // currency, and adding two currencies together would invent a figure
        // no checkout computes.
        $overflowing = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT CONCAT(BIN_TO_UUID(i.cart_id), ' (', SUM(p.price_amount * i.quantity), ' ', p.price_currency, ')')
                  FROM cart_item i
                  JOIN cart c ON c.id = i.cart_id
                  JOIN product p ON p.id = i.product_id
                 WHERE c.status = :pending
                 GROUP BY i.cart_id, p.price_currency
                HAVING SUM(p.price_amount * i.quantity) > :maximum
                 ORDER BY SUM(p.price_amount * i.quantity) DESC
                 LIMIT 10
                SQL,
            ['pending' => CartStatus::PENDING, 'maximum' => self::COLUMN_MAXIMUM],
        );

        $this->abortIf(
            [] !== $overflowing,
            \sprintf(
                'These pending carts already total more than the order columns can hold (%s), so checking one out '
                . 'would fail with an out-of-range error: %s. Reprice the products they hold - or, for one that is '
                . 'withdrawn and can no longer be repriced, cancel the cart through the API '
                . '(DELETE /v1/carts/{id}), which puts the units back on the shelf - then run this migration again.',
                self::COLUMN_MAXIMUM,
                implode(', ', array_map(strval(...), $overflowing)),
            ),
        );
    }

    public function up(Schema $schema): void
    {
        // A paid cart can be cancelled, and the order is the record of what was
        // bought. Left untouched it was still confirmed by the queued
        // CartCheckedOut consumer, which only asked whether the confirmation
        // had gone out, and every read of it showed a purchase the customer had
        // called off. Existing rows are NULL: nothing cancelled before now.
        $this->addSql('ALTER TABLE orders ADD canceled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP canceled_at');
    }
}
