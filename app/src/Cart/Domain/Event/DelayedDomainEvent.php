<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Event;

use Siroko\Cart\Domain\ValueObject\DateTime;

interface DelayedDomainEvent extends DomainEvent
{
    public function delayedOn(): DateTime;
}
