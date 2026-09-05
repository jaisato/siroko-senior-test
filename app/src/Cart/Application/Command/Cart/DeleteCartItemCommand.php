<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Exception\InvalidCustomerIdException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CustomerId;
use Siroko\Cart\Domain\ValueObject\ItemId;

final class DeleteCartItemCommand
{
    private readonly CartId $cartId;

    private readonly ItemId $itemId;

    private readonly ?CustomerId $customerId;

    /**
     * @throws InvalidIdentifierException
     * @throws InvalidCustomerIdException
     */
    public function __construct(string $cartId, string $itemId, ?string $customerId = null)
    {
        $this->cartId = CartId::fromString($cartId);
        $this->itemId = ItemId::fromString($itemId);
        $this->customerId = null === $customerId ? null : CustomerId::fromString($customerId);
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }

    public function itemId(): ItemId
    {
        return $this->itemId;
    }

    /**
     * The authenticated caller, when the API authenticates; null otherwise.
     */
    public function customer(): ?CustomerId
    {
        return $this->customerId;
    }
}
