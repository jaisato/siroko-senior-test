<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Queue;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Event\DelayedDomainEvent;
use Siroko\Cart\Domain\ValueObject\DateTime;
use Siroko\Cart\Infrastructure\Queue\MessageDispatcher;
use Siroko\Tests\Cart\Domain\Event\FakeEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class MessageDispatcherTest extends TestCase
{
    public function test_a_plain_event_is_dispatched_without_stamps(): void
    {
        $bus = $this->bus($envelope);

        $event = new FakeEvent();
        (new MessageDispatcher($bus))->dispatch($event);

        self::assertInstanceOf(Envelope::class, $envelope);
        self::assertSame($event, $envelope->getMessage());
        self::assertSame([], $envelope->all(DelayStamp::class));
    }

    public function test_a_delayed_event_carries_a_delay_stamp_until_its_due_time(): void
    {
        $bus = $this->bus($envelope);

        $event = new class implements DelayedDomainEvent {
            public function delayedOn(): DateTime
            {
                return DateTime::createIn('+2 hours');
            }

            public function ocurredOn(): int
            {
                return 0;
            }

            public function jsonSerialize(): array
            {
                return [];
            }

            public function commandArguments(): array
            {
                return [];
            }
        };

        (new MessageDispatcher($bus))->dispatch($event);

        self::assertInstanceOf(Envelope::class, $envelope);
        $stamps = $envelope->all(DelayStamp::class);
        self::assertCount(1, $stamps);
        $stamp = $stamps[0];
        // Two hours, give or take the seconds the test itself takes.
        self::assertEqualsWithDelta(2 * 3600 * 1000, $stamp->getDelay(), 5000);
    }

    private function bus(?Envelope &$captured): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$captured): Envelope {
            $captured = Envelope::wrap($message, $stamps);

            return $captured;
        });

        return $bus;
    }
}
