<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\ProductId;

final class AddCartProductCommand
{
    private readonly CartId $cartId;

    private readonly ProductId $productId;

    /**
     * @throws InvalidIdentifierException
     */
    public function __construct(string $cartId, string $productId)
    {
        $this->cartId = CartId::fromString($cartId);
        $this->productId = ProductId::fromString($productId);
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }
}
