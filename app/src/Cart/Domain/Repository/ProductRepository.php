<?php

namespace Siroko\Cart\Domain\Repository;

use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\ProductId;

interface ProductRepository
{
    /**
     * @return ProductId
     */
    public function nextIdentity(): ProductId;

    /**
     * @param Product $product
     * @return void
     */
    public function save(Product $product): void;

    /**
     * @param ProductId $id
     * @return Product|null
     */
    public function ofId(ProductId $id): ?Product;

    /**
     * @param int $pageNumber
     * @param int $pageSize
     * @return array|Product[]
     */
    public function findAll(int $pageNumber, int $pageSize): array;
}
