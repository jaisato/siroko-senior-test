<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\Event;

use Siroko\Cart\Domain\Event\DomainEvent;

/**
 * The smallest possible domain event, for tests of the event plumbing. The
 * application does not raise any event yet; this stands in for the ones to
 * come.
 */
final class FakeEvent implements DomainEvent
{
    /**
     * @param list<string> $arguments
     */
    public function __construct(
        private readonly array $arguments = ['some-id'],
        private readonly int $occurredOn = 1_700_000_000,
    ) {}

    public function ocurredOn(): int
    {
        return $this->occurredOn;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['arguments' => $this->arguments, 'occurredOn' => $this->occurredOn];
    }

    /**
     * @return string[]
     */
    public function commandArguments(): array
    {
        return $this->arguments;
    }
}
