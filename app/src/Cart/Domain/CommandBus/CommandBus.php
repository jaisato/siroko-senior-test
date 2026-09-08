<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\CommandBus;

interface CommandBus
{
    public function handle(object $command): mixed;
}
