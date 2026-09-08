<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Health;

use Siroko\Cart\Infrastructure\Health\HealthProbe;

/**
 * A dependency that is down, with the reason a real driver would give.
 */
final class FailingProbe implements HealthProbe
{
    public function __construct(
        private readonly string $name,
        private readonly \Throwable $failure = new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused (mysql://siroko:hunter2@db:3306)'),
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): void
    {
        throw $this->failure;
    }
}
