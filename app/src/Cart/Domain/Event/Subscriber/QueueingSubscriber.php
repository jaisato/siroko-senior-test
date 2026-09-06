<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Event\Subscriber;

use Siroko\Cart\Domain\Event\DomainEvent;
use Siroko\Cart\Domain\Event\DomainEventSubscriber;
use Siroko\Cart\Domain\Queue\MessageDispatcher;

/**
 * Puts every event on the queue the moment it is published - which, since
 * handlers publish inside `executeAtomically()`, means inside the transaction
 * that made the change.
 *
 * That is the outbox: the queue lives in the application's own database
 * (`messenger_messages`, doctrine transport) and the row is written on the
 * same connection, so the change and its announcement commit together or roll
 * back together. Collecting the events and dispatching them after the handler
 * returned - which is what this used to do - left a window in which the
 * checkout was committed and the process could die before the event reached
 * the queue: a paid order whose confirmation nothing would ever send.
 */
final class QueueingSubscriber implements DomainEventSubscriber
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function handle(DomainEvent $event): void
    {
        $this->dispatcher->dispatch($event);
    }

    public function isSubscribedTo(DomainEvent $event): bool
    {
        return true;
    }

    /**
     * Nothing is kept: an event is on the queue as soon as it is handled.
     *
     * @return DomainEvent[]
     */
    public function events(): array
    {
        return [];
    }
}
