<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\CommandBus\Middleware;

use League\Tactician\Middleware;
use Siroko\Cart\Domain\Event\DomainEventPublisher;
use Siroko\Cart\Domain\Event\Subscriber\QueueingSubscriber;
use Siroko\Cart\Domain\Queue\MessageDispatcher;

/**
 * Puts the domain events a command raises on the queue, and only for as long
 * as that command runs.
 *
 * The subscriber writes each event to the queue as it is published, which -
 * handlers publishing inside `executeAtomically()` - is inside the
 * transaction that made the change. The queue is a table in the same database
 * reached over the same connection, so the change and its announcement commit
 * together, or roll back together: that is the whole point of an outbox.
 * Collecting the events and dispatching them once the handler had returned,
 * which is what this used to do, kept a rolled-back command quiet but left a
 * window where the transaction was committed and the process could still die
 * before the event was queued - a paid order nobody would ever confirm.
 *
 * The subscription is removed whether the handler succeeds or throws. The
 * publisher is a process-wide singleton, and an earlier version subscribed a
 * new collector per command without ever removing it: in a long-running
 * worker every command left one more behind, each of them receiving every
 * later event, so memory grew without bound and an event raised by the N-th
 * command was also dispatched by the N-1 stale collectors.
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
        $subscription = $this->publisher->subscribe(new QueueingSubscriber($this->messageDispatcher));

        try {
            return $next($command);
        } finally {
            $this->publisher->unsubscribe($subscription);
        }
    }
}
