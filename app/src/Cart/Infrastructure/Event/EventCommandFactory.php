<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Event;

use Siroko\Cart\Domain\Event\DomainEvent;
use Webmozart\Assert\Assert;

final class EventCommandFactory
{
    /** @var array<string,string> */
    private array $commands = [];

    public function __construct() {}

    public function add(string $eventClassName, string $commandClassName): void
    {
        if (\array_key_exists($eventClassName, $this->commands)) {
            return;
        }

        $this->commands[$eventClassName] = $commandClassName;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function get(DomainEvent $event): string
    {
        $eventClassName = $event::class;

        Assert::keyExists($this->commands, $eventClassName);

        return $this->commands[$eventClassName];
    }
}
