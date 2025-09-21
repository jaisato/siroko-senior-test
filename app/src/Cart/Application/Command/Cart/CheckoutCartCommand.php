<?php

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\ValueObject\CartId;

final class CheckoutCartCommand
{
    /**
     * @var CartId
     */
    private CartId $cartId;

    /**
     * @param string $cartId
     */
    public function __construct(
        string $cartId,
    ) {
        $this->cartId = CartId::fromString($cartId);
    }

    /**
     * @return CartId
     */
    public function cartId(): CartId
    {
        return $this->cartId;
    }
}
