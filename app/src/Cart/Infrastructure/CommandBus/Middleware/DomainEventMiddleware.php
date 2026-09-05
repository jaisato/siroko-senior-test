<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\CommandBus\Middleware;

use League\Tactician\Middleware;
use Siroko\Cart\Domain\Event\DomainEvent;
use Siroko\Cart\Domain\Event\DomainEventPublisher;
use Siroko\Cart\Domain\Event\Subscriber\InMemoryAllSubscriber;
use Siroko\Cart\Domain\Queue\MessageDispatcher;

/**
 * Collects the domain events raised while a command runs and hands them to
 * the message dispatcher once the handler has returned.
 *
 * The collector is unsubscribed whether the handler succeeds or throws. The
 * publisher is a process-wide singleton, and the previous version subscribed
 * a new collector per command without ever removing it: in a long-running
 * worker every command left one more collector behind, each of them receiving
 * every later event, so memory grew without bound and an event raised by the
 * N-th command was also recorded by the N-1 stale collectors.
 */
final class DomainEventMiddleware implements Middleware
{
    public function __construct(
        private readonly MessageDispatcher $messageDispatcher,
        private readonly DomainEventPublisher $publisher,
    ) {}

    /**
     * @param object $command
     */
    public function execute($command, callable $next): mixed
    {
        $collector = new InMemoryAllSubscriber();
        $subscription = $this->publisher->subscribe($collector);

        try {
            $returnValue = $next($command);
        } finally {
            $this->publisher->unsubscribe($subscription);
        }

        // Events are only published for a command that completed; a handler
        // that threw has been rolled back and its events describe nothing.
        $this->dispatchEvents($collector->events());

        return $returnValue;
    }

    /**
     * @param DomainEvent[] $events
     */
    private function dispatchEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->messageDispatcher->dispatch($event);
        }
    }
}
