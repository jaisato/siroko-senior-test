<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Health;

use Siroko\Cart\Infrastructure\Health\HealthProbe;

final class PassingProbe implements HealthProbe
{
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): void {}
}
