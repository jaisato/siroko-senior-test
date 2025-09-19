<?php

namespace Siroko\Cart\Domain\Queue;

interface MessageDispatcher
{
    public function dispatch(object $message): void;
}
