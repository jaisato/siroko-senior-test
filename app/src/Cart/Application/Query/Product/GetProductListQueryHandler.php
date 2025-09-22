<?php

namespace Siroko\Cart\Application\Query\Product;

use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Infrastructure\Api\Dto\Product\ProductReadCollection;

class GetProductListQueryHandler
{
    /**
     * @param ProductRepository $productRepository
     */
    public function __construct(
        private ProductRepository $productRepository
    ) {
    }

    /**
     * @param GetProductListQuery $query
     * @return ProductReadCollection
     * @throws \Brick\Money\Exception\UnknownCurrencyException
     */
    public function __invoke(GetProductListQuery $query): ProductReadCollection
    {
        $products = $this->productRepository->findAll($query->pageNumber, $query->pageSize);
        return ProductReadCollection::fromArray($products);
    }
}
