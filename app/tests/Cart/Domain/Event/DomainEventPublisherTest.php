<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\Event;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Event\DomainEvent;
use Siroko\Cart\Domain\Event\DomainEventPublisher;
use Siroko\Cart\Domain\Event\DomainEventSubscriber;
use Siroko\Cart\Domain\Event\Subscriber\InMemoryAllSubscriber;

final class DomainEventPublisherTest extends TestCase
{
    public function test_it_is_a_singleton_that_cannot_be_cloned(): void
    {
        $publisher = DomainEventPublisher::instance();

        self::assertSame($publisher, DomainEventPublisher::instance());

        $this->expectException(\BadMethodCallException::class);
        $copy = clone $publisher;
        self::fail(\sprintf('cloning should have failed, got %s', $copy::class));
    }

    public function test_subscribers_receive_the_events_they_are_subscribed_to(): void
    {
        $publisher = DomainEventPublisher::instance();
        $all = new InMemoryAllSubscriber();
        $none = new class implements DomainEventSubscriber {
            /** @var list<DomainEvent> */
            public array $handled = [];

            public function handle(DomainEvent $event): void
            {
                $this->handled[] = $event;
            }

            public function isSubscribedTo(DomainEvent $event): bool
            {
                return false;
            }

            public function events(): array
            {
                return $this->handled;
            }
        };

        $allId = $publisher->subscribe($all);
        $noneId = $publisher->subscribe($none);

        try {
            $event = new FakeEvent();
            $publisher->publish($event);
        } finally {
            $publisher->unsubscribe($allId);
            $publisher->unsubscribe($noneId);
        }

        self::assertSame([$event], $all->events());
        self::assertSame([], $none->events());
    }

    public function test_an_unsubscribed_subscriber_hears_nothing_more(): void
    {
        $publisher = DomainEventPublisher::instance();
        $subscriber = new InMemoryAllSubscriber();

        $id = $publisher->subscribe($subscriber);
        $publisher->unsubscribe($id);
        $publisher->unsubscribe($id);
        $publisher->publish(new FakeEvent());

        self::assertSame([], $subscriber->events());
    }

    public function test_subscription_ids_are_never_reused(): void
    {
        $publisher = DomainEventPublisher::instance();

        $first = $publisher->subscribe(new InMemoryAllSubscriber());
        $publisher->unsubscribe($first);
        $second = $publisher->subscribe(new InMemoryAllSubscriber());
        $publisher->unsubscribe($second);

        self::assertGreaterThan($first, $second);
    }
}
