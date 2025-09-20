<?php

namespace Siroko\Cart\Application\Query\Cart;

use Siroko\Cart\Domain\ValueObject\CartId;

class GetCartByIdQuery
{
    /**
     * @var CartId
     */
    private CartId $cartId;

    /**
     * @param string $id
     */
    public function __construct(string $id)
    {
        $this->cartId = CartId::fromString($id);
    }

    /**
     * @return CartId
     */
    public function cartId(): CartId
    {
        return $this->cartId;
    }
}
