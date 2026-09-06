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
 */
final class Version20260906180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cart_item.paid_price_amount / paid_price_currency: what a settled line was paid';
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
