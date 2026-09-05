<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Orders: the record a checkout leaves behind.
 *
 * The cart is a working document whose lines follow the products; the order
 * keeps the lines as they were paid (a JSON snapshot) and the total that was
 * captured. `cart_id` is a plain typed column rather than a foreign key: the
 * order must not follow the cart, and carts are never deleted anyway.
 */
final class Version20260906120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'orders: snapshot of a paid cart with its captured total and confirmation timestamp';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE orders (
                id BINARY(16) NOT NULL,
                cart_id BINARY(16) NOT NULL,
                lines JSON NOT NULL,
                item_count INT NOT NULL,
                total_amount NUMERIC(19, 4) NOT NULL,
                total_currency VARCHAR(3) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                confirmed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_orders_cart (cart_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE orders');
    }
}
