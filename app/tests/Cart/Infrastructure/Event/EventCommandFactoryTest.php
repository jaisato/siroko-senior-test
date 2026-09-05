<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Event;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Infrastructure\Event\EventCommandFactory;
use Siroko\Tests\Cart\Domain\Event\FakeEvent;

final class EventCommandFactoryTest extends TestCase
{
    public function test_it_resolves_the_command_registered_for_an_event(): void
    {
        $factory = new EventCommandFactory();
        $factory->add(FakeEvent::class, \stdClass::class);

        self::assertSame(\stdClass::class, $factory->get(new FakeEvent()));
    }

    public function test_the_first_registration_wins(): void
    {
        $factory = new EventCommandFactory();
        $factory->add(FakeEvent::class, \stdClass::class);
        $factory->add(FakeEvent::class, \ArrayObject::class);

        self::assertSame(\stdClass::class, $factory->get(new FakeEvent()));
    }

    public function test_an_event_without_a_command_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new EventCommandFactory())->get(new FakeEvent());
    }
}
