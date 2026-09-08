<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Health;

use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The queue the domain events travel by can be asked how long it is.
 *
 * With the Doctrine transport that is one `SELECT COUNT` on
 * `messenger_messages`: the cheapest request that proves the table exists,
 * is readable and holds what the transport expects - and, since the table
 * comes from a migration rather than from `auto_setup`, the check a fresh
 * deployment most needs. A transport that cannot be counted keeps its
 * messages in the process (in-memory in the test environment, `sync://`)
 * and has nothing that could be down.
 */
final class MessengerProbe implements HealthProbe
{
    public const NAME = 'messenger';

    public function __construct(private readonly TransportInterface $transport) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function check(): void
    {
        if ($this->transport instanceof MessageCountAwareInterface) {
            $this->transport->getMessageCount();
        }
    }
}
