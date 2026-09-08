<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Cart;

use Siroko\Cart\Domain\Exception\InvalidCustomerIdException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CustomerId;

final class GetCartByIdQuery
{
    private readonly CartId $cartId;

    private readonly ?CustomerId $customerId;

    /**
     * @throws InvalidIdentifierException
     * @throws InvalidCustomerIdException
     */
    public function __construct(string $id, ?string $customerId = null)
    {
        $this->cartId = CartId::fromString($id);
        $this->customerId = null === $customerId ? null : CustomerId::fromString($customerId);
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }

    /**
     * The authenticated caller, when the API authenticates; null otherwise.
     */
    public function customer(): ?CustomerId
    {
        return $this->customerId;
    }
}
