<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Order;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\OrderId;

/**
 * The command a `CartCheckedOut` event becomes on the worker side; see
 * EventCommandFactory in the service configuration for the mapping. Its
 * constructor takes exactly the event's `commandArguments()`.
 */
final class SendOrderConfirmationCommand
{
    private readonly OrderId $orderId;

    /**
     * @throws InvalidIdentifierException
     */
    public function __construct(string $orderId)
    {
        $this->orderId = OrderId::fromString($orderId);
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }
}
