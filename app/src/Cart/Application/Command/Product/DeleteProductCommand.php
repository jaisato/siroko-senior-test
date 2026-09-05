<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Product;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\ProductId;

final class DeleteProductCommand
{
    private readonly ProductId $id;

    /**
     * @throws InvalidIdentifierException
     */
    public function __construct(string $id)
    {
        $this->id = ProductId::fromString($id);
    }

    public function id(): ProductId
    {
        return $this->id;
    }
}
