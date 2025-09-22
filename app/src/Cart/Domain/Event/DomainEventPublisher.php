<?php

namespace Siroko\Cart\Domain\Event;

use BadMethodCallException;

final class DomainEventPublisher
{
    /** @var DomainEventSubscriber[] */
    private array $subscribers = [];

    private static DomainEventPublisher $instance;

    private int $id = 0;

    private function __construct()
    {
    }

    /**
     * @return DomainEventPublisher
     */
    public static function instance(): self
    {
        if (! isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @throws BadMethodCallException
     */
    public function __clone()
    {
        throw new BadMethodCallException('Clone is not supported');
    }

    public function subscribe(DomainEventSubscriber $subscriber): int
    {
        $id                     = $this->id;
        $this->subscribers[$id] = $subscriber;
        $this->id++;

        return $id;
    }

    public function unsubscribe(int $id): void
    {
        if (! isset($this->subscribers[$id])) {
            return;
        }

        unset($this->subscribers[$id]);
    }

    public function publish(DomainEvent $domainEvent): void
    {
        foreach ($this->subscribers as $aSubscriber) {
            if (! $aSubscriber->isSubscribedTo($domainEvent)) {
                continue;
            }

            $aSubscriber->handle($domainEvent);
        }
    }
}
