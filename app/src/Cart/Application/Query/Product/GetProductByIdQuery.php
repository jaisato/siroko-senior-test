<?php

namespace Siroko\Cart\Application\Query\Product;

use Siroko\Cart\Domain\ValueObject\ProductId;

class GetProductByIdQuery
{
    /**
     * @var ProductId
     */
    private ProductId $id;

    /**
     * @param string $id
     */
    public function __construct(
        string $id,
    ) {
        $this->id = ProductId::fromString($id);
    }

    /**
     * @return ProductId
     */
    public function getId(): ProductId
    {
        return $this->id;
    }
}
