<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A product code identifies exactly one product: unique index on product.code.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_product_code ON product (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_product_code ON product');
    }
}
