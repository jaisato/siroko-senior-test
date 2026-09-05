<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Queue;

use League\Tactician\CommandBus as TacticianBus;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Infrastructure\CommandBus\CommandBusCli;
use Siroko\Cart\Infrastructure\Event\EventCommandFactory;
use Siroko\Cart\Infrastructure\Queue\Consumer\DomainEventConsumer;
use Siroko\Tests\Cart\Domain\Event\FakeEvent;

final class DomainEventConsumerTest extends TestCase
{
    public function test_it_turns_an_event_into_its_command_and_runs_it_on_the_cli_bus(): void
    {
        $factory = new EventCommandFactory();
        $factory->add(FakeEvent::class, RecordedCommand::class);

        $handled = [];
        $tactician = $this->createStub(TacticianBus::class);
        $tactician->method('handle')->willReturnCallback(static function (object $command) use (&$handled): mixed {
            $handled[] = $command;

            return null;
        });

        $consumer = new DomainEventConsumer($factory, new CommandBusCli($tactician));

        $consumer(new FakeEvent(['cart-1', 'product-2']));

        self::assertCount(1, $handled);
        self::assertInstanceOf(RecordedCommand::class, $handled[0]);
        self::assertSame(['cart-1', 'product-2'], $handled[0]->arguments);
    }

    public function test_an_event_nobody_mapped_is_refused_instead_of_being_swallowed(): void
    {
        $tactician = $this->createStub(TacticianBus::class);
        $tactician->method('handle')->willReturnCallback(static fn() => self::fail('nothing should be handled'));

        $consumer = new DomainEventConsumer(new EventCommandFactory(), new CommandBusCli($tactician));

        $this->expectException(\InvalidArgumentException::class);

        $consumer(new FakeEvent());
    }
}

final class RecordedCommand
{
    /** @var list<string> */
    public array $arguments;

    public function __construct(string ...$arguments)
    {
        $this->arguments = array_values($arguments);
    }
}
