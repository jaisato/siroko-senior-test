<?php

namespace Siroko\Cart\Infrastructure\Queue;

use Siroko\Cart\Domain\Event\DelayedDomainEvent;
use Siroko\Cart\Domain\ValueObject\DateTime;
use Siroko\Cart\Infrastructure\Service\Utilities;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class MessageDispatcher implements \Siroko\Cart\Domain\Queue\MessageDispatcher
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public function dispatch(object $message): void
    {
        $this->messageBus->dispatch(
            $message,
            self::envelopes($message)
        );
    }

    /**
     * @return StampInterface[]
     */
    private static function envelopes(object $message): array
    {
        if (! $message instanceof DelayedDomainEvent) {
            return [];
        }

        return [
            new DelayStamp(
                Utilities::millisecondsBetweenTwoDateTime(
                    $message->delayedOn(),
                    DateTime::now()
                )
            ),
        ];
    }
}
