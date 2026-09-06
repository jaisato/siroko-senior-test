<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Health;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Infrastructure\Health\DatabaseProbe;

final class DatabaseProbeTest extends TestCase
{
    public function test_it_passes_when_the_database_answers(): void
    {
        $probe = new DatabaseProbe(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));

        $probe->check();

        self::assertSame('database', $probe->name());
    }

    /** A database that cannot be reached is the driver's exception, not a quiet "ok". */
    public function test_it_fails_when_the_database_cannot_be_reached(): void
    {
        $probe = new DatabaseProbe(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => '/nonexistent/directory/health.db']));

        $this->expectException(DbalException::class);

        $probe->check();
    }
}
