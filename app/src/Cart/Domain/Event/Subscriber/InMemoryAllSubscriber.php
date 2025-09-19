<?php

namespace Siroko\Cart\Domain\Event\Subscriber;

use Siroko\Cart\Domain\Event\DomainEvent;
use Siroko\Cart\Domain\Event\DomainEventSubscriber;

class InMemoryAllSubscriber implements DomainEventSubscriber
{
    /** @var DomainEvent[] */
    private array $events = [];

    public function __construct()
    {
    }

    public function handle(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    public function isSubscribedTo(DomainEvent $event): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function events(): array
    {
        return $this->events;
    }
}
