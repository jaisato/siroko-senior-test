<?php

namespace Siroko\Cart\Domain\Event;

use JsonSerializable;

interface DomainEvent extends JsonSerializable
{
    public function ocurredOn(): int;

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array;

    /**
     * @return string[]
     */
    public function commandArguments(): array;
}
