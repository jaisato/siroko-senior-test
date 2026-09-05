<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Product;

use Siroko\Cart\Application\Dto\Product\ProductReadCollection;
use Siroko\Cart\Domain\Repository\ProductRepository;

final class GetProductListQueryHandler
{
    public function __construct(
        private readonly ProductRepository $productRepository,
    ) {}

    public function __invoke(GetProductListQuery $query): ProductReadCollection
    {
        $products = $this->productRepository->findAll($query->pageNumber, $query->pageSize);

        return ProductReadCollection::fromArray(
            $products,
            $query->pageNumber,
            $query->pageSize,
            $this->productRepository->countAll(),
        );
    }
}
