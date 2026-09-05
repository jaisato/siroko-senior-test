<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Order;

use Psr\Log\AbstractLogger;

/**
 * Keeps every record it is given, for handlers whose visible effect is a log line.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
