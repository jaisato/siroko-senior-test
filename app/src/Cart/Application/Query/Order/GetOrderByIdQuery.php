<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Order;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\OrderId;

final class GetOrderByIdQuery
{
    private readonly OrderId $orderId;

    /**
     * @throws InvalidIdentifierException
     */
    public function __construct(string $id)
    {
        $this->orderId = OrderId::fromString($id);
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }
}
