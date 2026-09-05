<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Responses remembered for Idempotency-Key replays (POST /v1/carts and the
 * add/checkout PUTs). Rows expire after IDEMPOTENCY_TTL and are removed by
 * `idempotency:purge-expired`.
 */
final class Version20260906160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'idempotency_key: stored responses for Idempotency-Key replays';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE idempotency_key (
                id CHAR(64) NOT NULL,
                scope VARCHAR(64) NOT NULL,
                request_key VARCHAR(255) NOT NULL,
                fingerprint CHAR(64) NOT NULL,
                response_status SMALLINT NOT NULL,
                response_content_type VARCHAR(100) NOT NULL,
                response_body LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_idempotency_expires (expires_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idempotency_key');
    }
}
