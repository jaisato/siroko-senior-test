<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\CommandBus\Middleware;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Event\DomainEvent;
use Siroko\Cart\Domain\Event\DomainEventPublisher;
use Siroko\Cart\Domain\Event\Subscriber\InMemoryAllSubscriber;
use Siroko\Cart\Domain\Queue\MessageDispatcher;
use Siroko\Cart\Infrastructure\CommandBus\Middleware\DomainEventMiddleware;
use Siroko\Tests\Cart\Domain\Event\FakeEvent;

final class DomainEventMiddlewareTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private DomainEventMiddleware $middleware;

    private DomainEventPublisher $publisher;

    protected function setUp(): void
    {
        $this->dispatched = [];
        $this->publisher = DomainEventPublisher::instance();

        $dispatcher = $this->createStub(MessageDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $message): void {
            $this->dispatched[] = $message;
        });

        $this->middleware = new DomainEventMiddleware($dispatcher, $this->publisher);
    }

    /**
     * Queued as it is published, which is inside the handler's transaction:
     * the queue is a table on the same connection, so the change and its
     * announcement commit or roll back as one (see OutboxTest for the proof
     * against a real doctrine transport). Dispatching after the handler
     * returned instead kept a rolled-back command quiet, but left the window
     * where the commit had happened and the process could still die before
     * the event was queued.
     */
    public function test_events_raised_by_the_handler_are_queued_as_they_are_published(): void
    {
        $event = new FakeEvent();

        $result = $this->middleware->execute(new \stdClass(), function () use ($event): string {
            $this->publisher->publish($event);
            self::assertSame([$event], $this->dispatched, 'on the queue already, inside the transaction');

            return 'handled';
        });

        self::assertSame('handled', $result);
        self::assertSame([$event], $this->dispatched);
    }

    /**
     * The publisher is a process-wide singleton. Each command subscribed a new
     * subscriber and never removed it, so in a worker every command leaked
     * one, and an event raised by the N-th command was also dispatched by the
     * N-1 stale ones.
     */
    public function test_the_subscriber_is_unsubscribed_once_the_command_is_done(): void
    {
        $this->middleware->execute(new \stdClass(), static fn(): int => 1);

        $this->dispatched = [];
        $witness = new InMemoryAllSubscriber();
        $subscription = $this->publisher->subscribe($witness);

        try {
            $this->publisher->publish(new FakeEvent());
        } finally {
            $this->publisher->unsubscribe($subscription);
        }

        self::assertCount(1, $witness->events(), 'the witness still hears events');
        self::assertSame([], $this->dispatched, 'the middleware no longer queues them');
    }

    public function test_the_subscriber_is_unsubscribed_even_when_the_handler_throws(): void
    {
        try {
            $this->middleware->execute(new \stdClass(), static function (): never {
                throw new \RuntimeException('handler failed');
            });
            self::fail('the exception propagates');
        } catch (\RuntimeException $e) {
            self::assertSame('handler failed', $e->getMessage());
        }

        $this->publisher->publish(new FakeEvent());

        self::assertSame([], $this->dispatched, 'no event leaks out of a failed command');
    }

    /**
     * The subscription is what a failed command loses, not the events it had
     * already queued: those are rows in the transaction that is rolling back,
     * and the database takes them with it. What must not happen is the
     * command's own failure leaking the subscription to whatever runs next.
     */
    public function test_a_failed_command_stops_queueing_at_the_point_it_failed(): void
    {
        $published = new FakeEvent();

        try {
            $this->middleware->execute(new \stdClass(), function () use ($published): never {
                $this->publisher->publish($published);

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([$published], $this->dispatched, 'queued inside the transaction that then rolled back');

        $this->dispatched = [];
        $this->publisher->publish(new FakeEvent());
        self::assertSame([], $this->dispatched, 'and nothing is queued once the command is over');
    }

    public function test_a_second_command_does_not_see_the_events_of_the_first(): void
    {
        $first = new FakeEvent();
        $second = new FakeEvent();

        $this->middleware->execute(new \stdClass(), function () use ($first): void {
            $this->publisher->publish($first);
        });
        $this->middleware->execute(new \stdClass(), function () use ($second): void {
            $this->publisher->publish($second);
        });

        self::assertSame([$first, $second], $this->dispatched);
        self::assertContainsOnlyInstancesOf(DomainEvent::class, $this->dispatched);
    }
}
