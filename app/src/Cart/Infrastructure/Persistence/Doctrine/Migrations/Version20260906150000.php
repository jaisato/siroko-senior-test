<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Carts and orders know their customer when the API authenticates callers.
 * Rows that predate authentication stay ownerless (NULL) and remain open to
 * any authenticated caller, so turning authentication on strands nothing.
 */
final class Version20260906150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cart.customer_id / orders.customer_id, with an index for a customer\'s cart listing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart ADD customer_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_cart_customer_created ON cart (customer_id, created_at)');
        $this->addSql('ALTER TABLE orders ADD customer_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP customer_id');
        $this->addSql('DROP INDEX idx_cart_customer_created ON cart');
        $this->addSql('ALTER TABLE cart DROP customer_id');
    }
}
