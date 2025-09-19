<?php

namespace Siroko\Cart\Application\Query\Product;

use Brick\Money\Exception\UnknownCurrencyException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Infrastructure\Api\Dto\Product\ProductRead;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetProductByIdQueryHandler
{
    public function __construct(
        private readonly ProductRepository $repository
    ) {
    }

    /**
     * @param GetProductByIdQuery $query
     * @return ProductRead
     * @throws UnknownCurrencyException
     */
    public function __invoke(GetProductByIdQuery $query): ProductRead
    {
        $product = $this->repository->ofId($query->getId());

        if (null === $product) {
            throw new NotFoundHttpException("Product with id {$query->getId()} not found");
        }

        return ProductRead::fromModel($product);
    }
}
