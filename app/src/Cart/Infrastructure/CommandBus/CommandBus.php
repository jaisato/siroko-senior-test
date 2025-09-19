<?php

namespace Siroko\Cart\Infrastructure\CommandBus;

use League\Tactician\CommandBus as TacticianCommandBus;

abstract class CommandBus
{
    protected TacticianCommandBus $commandBus;

    public function __construct(TacticianCommandBus $commandBus)
    {
        $this->commandBus = $commandBus;
    }

    public function handle(object $command)
    {
        return $this->commandBus->handle($command);
    }
}
