<?php

namespace Siroko\Cart\Domain\Event;

use Siroko\Cart\Domain\ValueObject\DateTime;

interface DelayedDomainEvent extends DomainEvent
{
    public function delayedOn(): DateTime;
}
