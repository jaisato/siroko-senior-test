<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Product;

use Siroko\Cart\Application\Dto\Product\ProductRead;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\ProductRepository;

final class GetProductByIdQueryHandler
{
    public function __construct(
        private readonly ProductRepository $repository,
    ) {}

    /**
     * @throws ProductNotFoundException
     */
    public function __invoke(GetProductByIdQuery $query): ProductRead
    {
        $product = $this->repository->ofId($query->getId());

        if (null === $product) {
            throw ProductNotFoundException::withId($query->getId());
        }

        return ProductRead::fromModel($product);
    }
}
