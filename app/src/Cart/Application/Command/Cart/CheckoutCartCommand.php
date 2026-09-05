<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\CartId;

final class CheckoutCartCommand
{
    private readonly CartId $cartId;

    /**
     * @throws InvalidIdentifierException
     */
    public function __construct(string $cartId)
    {
        $this->cartId = CartId::fromString($cartId);
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }
}
