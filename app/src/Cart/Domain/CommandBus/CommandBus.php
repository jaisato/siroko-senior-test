<?php

namespace Siroko\Cart\Domain\CommandBus;

interface CommandBus
{
    public function handle(object $command);
}
