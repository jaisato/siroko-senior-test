<?php

namespace Siroko\Cart\Application\Command\Product;

use Brick\Money\Exception\UnknownCurrencyException;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Infrastructure\Api\Dto\Product\ProductRead;

class CreateProductCommandHandler
{
    /**
     * @param ProductRepository $productRepository
     */
    public function __construct(
        private readonly ProductRepository $productRepository,
    ) {
    }

    /**
     * @param CreateProductCommand $command
     * @return ProductRead
     * @throws UnknownCurrencyException
     * @throws InvalidQuantityException
     */
    public function __invoke(CreateProductCommand $command): ProductRead
    {
        $product = new Product(
            $this->productRepository->nextIdentity(),
            $command->getCode(),
            $command->getName(),
            $command->getPrice(),
            $command->getQuantity()
        );

        $this->productRepository->save($product);

        return ProductRead::fromModel($product);
    }
}
