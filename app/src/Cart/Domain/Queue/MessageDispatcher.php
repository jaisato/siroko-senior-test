<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Queue;

interface MessageDispatcher
{
    public function dispatch(object $message): void;
}
