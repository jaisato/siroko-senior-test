<?php
declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\CommandBus\Middleware;

use Siroko\Cart\Domain\Event\DomainEvent;
use Siroko\Cart\Domain\Event\DomainEventPublisher;
use Siroko\Cart\Domain\Event\Subscriber\InMemoryAllSubscriber;
use Siroko\Cart\Domain\Queue\MessageDispatcher;
use League\Tactician\Middleware;

use function array_map;

final class DomainEventMiddleware implements Middleware
{
    private MessageDispatcher $messageDispatcher;

    public function __construct(MessageDispatcher $messageDispatcher)
    {
        $this->messageDispatcher = $messageDispatcher;
    }

    /**
     * @inheritDoc
     */
    public function execute($command, callable $next)
    {
        $domainEventPublisher = DomainEventPublisher::instance();
        $domainEventsCollector = new InMemoryAllSubscriber();
        $domainEventPublisher->subscribe($domainEventsCollector);

        $returnValue = $next($command);

        $this->dispatchEvents($domainEventsCollector->events());

        return $returnValue;
    }

    /**
     * @param DomainEvent[] $events
     */
    private function dispatchEvents(array $events): void
    {
        array_map(
            function (DomainEvent $event): void {
                $this->messageDispatcher->dispatch($event);
            },
            $events
        );
    }
}
