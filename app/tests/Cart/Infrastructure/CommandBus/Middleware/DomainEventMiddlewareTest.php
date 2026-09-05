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

    public function test_events_raised_by_the_handler_are_dispatched_after_it_returns(): void
    {
        $event = new FakeEvent();

        $result = $this->middleware->execute(new \stdClass(), function () use ($event): string {
            $this->publisher->publish($event);
            self::assertSame([], $this->dispatched, 'nothing is dispatched while the handler runs');

            return 'handled';
        });

        self::assertSame('handled', $result);
        self::assertSame([$event], $this->dispatched);
    }

    /**
     * The publisher is a process-wide singleton. Each command subscribed a new
     * collector and never removed it, so in a worker every command leaked one
     * collector, and an event raised by the N-th command was also recorded -
     * and dispatched - by the N-1 stale collectors.
     */
    public function test_the_collector_is_unsubscribed_once_the_command_is_done(): void
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
        self::assertSame([], $this->dispatched, 'the middleware no longer does');
    }

    public function test_the_collector_is_unsubscribed_even_when_the_handler_throws(): void
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

    public function test_events_of_a_failed_command_are_not_dispatched(): void
    {
        try {
            $this->middleware->execute(new \stdClass(), function (): never {
                $this->publisher->publish(new FakeEvent());

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->dispatched);
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
