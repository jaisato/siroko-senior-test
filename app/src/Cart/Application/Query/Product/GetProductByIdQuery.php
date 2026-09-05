<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Product;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\ProductId;

final class GetProductByIdQuery
{
    private readonly ProductId $id;

    /**
     * @throws InvalidIdentifierException
     */
    public function __construct(string $id)
    {
        $this->id = ProductId::fromString($id);
    }

    public function getId(): ProductId
    {
        return $this->id;
    }
}
