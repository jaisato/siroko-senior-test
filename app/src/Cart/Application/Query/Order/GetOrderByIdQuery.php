<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Order;

use Siroko\Cart\Domain\Exception\InvalidCustomerIdException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\CustomerId;
use Siroko\Cart\Domain\ValueObject\OrderId;

final class GetOrderByIdQuery
{
    private readonly OrderId $orderId;

    private readonly ?CustomerId $customerId;

    /**
     * @throws InvalidIdentifierException
     * @throws InvalidCustomerIdException
     */
    public function __construct(string $id, ?string $customerId = null)
    {
        $this->orderId = OrderId::fromString($id);
        $this->customerId = null === $customerId ? null : CustomerId::fromString($customerId);
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }

    /**
     * The authenticated caller, when the API authenticates; null otherwise.
     */
    public function customer(): ?CustomerId
    {
        return $this->customerId;
    }
}
