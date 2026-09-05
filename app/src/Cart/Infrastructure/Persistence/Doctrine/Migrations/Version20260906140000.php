<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Products can be withdrawn from the catalogue without losing the row that
 * cart lines and order snapshots reference (their foreign key is RESTRICT).
 */
final class Version20260906140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'product.deleted_at: soft delete';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE product ADD deleted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP deleted_at');
    }
}
