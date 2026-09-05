<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Cart;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\CartId;

final class GetCartByIdQuery
{
    private readonly CartId $cartId;

    /**
     * @throws InvalidIdentifierException
     */
    public function __construct(string $id)
    {
        $this->cartId = CartId::fromString($id);
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }
}
