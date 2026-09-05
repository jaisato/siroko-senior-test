<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Carts know when they were opened and when their reservation lapses.
 *
 * Existing rows are dated at migration time, the only instant known for
 * them, and the pending ones are given the default deadline (30 minutes,
 * CART_RESERVATION_TTL's default) so that the release sweep picks them up
 * instead of leaving their units reserved forever.
 */
final class Version20260906130000 extends AbstractMigration
{
    private const DEFAULT_TTL_SECONDS = 1800;

    public function getDescription(): string
    {
        return 'cart.created_at / cart.expires_at, with an index for the expiry sweep';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cart ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT '(DC2Type:datetime_immutable)', ADD expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql(\sprintf('UPDATE cart SET expires_at = DATE_ADD(created_at, INTERVAL %d SECOND) WHERE status = 1', self::DEFAULT_TTL_SECONDS));
        // The default only served the backfill; the application always writes the instant.
        $this->addSql('ALTER TABLE cart ALTER created_at DROP DEFAULT');
        $this->addSql('CREATE INDEX idx_cart_status_expires ON cart (status, expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cart_status_expires ON cart');
        $this->addSql('ALTER TABLE cart DROP created_at, DROP expires_at');
    }
}
