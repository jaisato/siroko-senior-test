<?php

namespace Siroko\Cart\Domain\Event;

interface DomainEventSubscriber
{
    public function handle(DomainEvent $event): void;

    public function isSubscribedTo(DomainEvent $event): bool;

    /**
     * @return DomainEvent[]
     */
    public function events(): array;
}
