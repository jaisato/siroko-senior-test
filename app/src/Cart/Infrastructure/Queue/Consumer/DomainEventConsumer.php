<?php

namespace Siroko\Cart\Infrastructure\Queue\Consumer;

use Siroko\Cart\Domain\Event\DomainEvent;
use Siroko\Cart\Infrastructure\CommandBus\CommandBusCli;
use Siroko\Cart\Infrastructure\Event\EventCommandFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class DomainEventConsumer
{
    private EventCommandFactory $eventCommandFactory;

    private CommandBusCli $commandBus;

    public function __construct(
        EventCommandFactory $eventCommandFactory,
        CommandBusCli $commandBus
    ) {
        $this->eventCommandFactory = $eventCommandFactory;
        $this->commandBus          = $commandBus;
    }

    public function __invoke(DomainEvent $event): void
    {
        $this->commandBus->handle(
            $this->command($event)
        );
    }

    private function command(DomainEvent $event): object
    {
        $commandClassName = $this->eventCommandFactory->get($event);

        return new $commandClassName(...$event->commandArguments());
    }
}
