<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What each cart line was actually paid.
 *
 * A line points at a product rather than holding a copy of its price, which is
 * right while the cart is pending - the customer sees today's price - and wrong
 * the moment it is paid for. The catalogue moves on, and a paid cart's totals
 * moved with it: an amount edited to something else changed a completed
 * purchase in silence, and a reprice into another currency made
 * `Cart::subtotal()` throw from then on, so reading that cart answered 409 and
 * the queued order confirmation rolled back, for a purchase that was finished.
 *
 * Cart::pay() now copies the unit price into these columns, and a settled line
 * reads them instead of the product. They stay NULL while a cart is pending,
 * which is why they are two plain columns and not an embedded Price: an
 * embeddable is never null in Doctrine, and a pending row would hydrate a Price
 * whose typed properties were never set.
 *
 * The backfill can only use the price the catalogue holds now - it is the one
 * figure the schema ever kept for these rows. Where the two differ, `orders`
 * is the record of what was actually paid: its lines were snapshots from the
 * start, and nothing here touches them.
 *
 * One shape of legacy row it cannot carry forward at all: a cart whose lines
 * point at products in two currencies. Cart::ensureSameCurrency() refuses to
 * build one today, so only an upgraded database can hold it - and from here on
 * Cart::subtotal() throws for it, which makes the cart unreadable and, while it
 * is pending, unpayable. Those carts stop this migration before anything is
 * written; see preUp().
 */
final class Version20260906180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cart_item.paid_price_amount / paid_price_currency: what a settled line was paid';
    }

    /**
     * Before any DDL, so a database holding a cart the new rules cannot read is
     * left exactly as it was - and before the backfill in particular, which is
     * what would freeze a settled cart's two currencies into its own columns.
     */
    public function preUp(Schema $schema): void
    {
        // A cart is priced in one currency: addItem() and the checkout both
        // refuse a product in another, and subtotal() adds the lines together
        // without asking. A row written before that rule existed adds up to
        // nothing at all - PriceIsNotSameCurrencyException on every read of the
        // cart, and no checkout for it while it is pending - and no migration
        // can choose which of the two currencies the customer meant.
        /** @var list<string> $mixed */
        $mixed = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT CONCAT(BIN_TO_UUID(i.cart_id), ' (', GROUP_CONCAT(DISTINCT p.price_currency SEPARATOR '/'), ')')
                  FROM cart_item i
                  JOIN product p ON p.id = i.product_id
                 GROUP BY i.cart_id
                HAVING COUNT(DISTINCT p.price_currency) > 1
                 ORDER BY i.cart_id
                 LIMIT 10
                SQL,
        );

        $this->abortIf([] !== $mixed, \sprintf(
            'These carts hold lines in more than one currency, which no cart may: every read of them would fail '
            . 'to add up, and a pending one could not be checked out: %s. Cancel the pending ones through the API '
            . '(DELETE /v1/carts/{id}), which puts the units back on the shelf; for a settled one the matching '
            . 'row in `orders` is the record of what was paid, and its lines are the figures to reconcile the '
            . 'cart with. Then run this migration again.',
            implode(', ', array_map(strval(...), $mixed)),
        ));
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart_item ADD paid_price_amount NUMERIC(19, 4) DEFAULT NULL, ADD paid_price_currency VARCHAR(3) DEFAULT NULL');

        // Every line of a cart that is no longer pending: paid, delivered or
        // canceled. A pending one keeps reading the product, deliberately.
        $this->addSql(<<<'SQL'
            UPDATE cart_item i
              JOIN cart c ON c.id = i.cart_id
              JOIN product p ON p.id = i.product_id
               SET i.paid_price_amount = p.price_amount,
                   i.paid_price_currency = p.price_currency
             WHERE c.status <> 1 -- CartStatus::PENDING
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart_item DROP paid_price_amount, DROP paid_price_currency');
    }
}
