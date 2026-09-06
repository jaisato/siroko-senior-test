<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Health;

use Doctrine\DBAL\Connection;

/**
 * The database answers: a round trip through the driver that needs no table
 * and no right beyond connecting.
 */
final class DatabaseProbe implements HealthProbe
{
    public const NAME = 'database';

    public function __construct(private readonly Connection $connection) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function check(): void
    {
        // The platform's own "SELECT 1"; connecting, which is what usually
        // fails, happens on the way to the platform.
        $this->connection
            ->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL())
            ->fetchOne();
    }
}
