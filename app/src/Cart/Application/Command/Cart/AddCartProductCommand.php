<?php

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\ProductId;

class AddCartProductCommand
{
    /**
     * @var CartId
     */
    private CartId $cartId;

    /**
     * @var ProductId
     */
    private ProductId $productId;

    /**
     * @param string $cartId
     * @param string $productId
     */
    public function __construct(string $cartId, string $productId)
    {
        $this->cartId = CartId::fromString($cartId);
        $this->productId = ProductId::fromString($productId);
    }

    /**
     * @return CartId
     */
    public function cartId(): CartId
    {
        return $this->cartId;
    }

    /**
     * @return ProductId
     */
    public function productId(): ProductId
    {
        return $this->productId;
    }
}
