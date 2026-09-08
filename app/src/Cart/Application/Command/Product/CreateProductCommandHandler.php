<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Product;

use Siroko\Cart\Application\Dto\Product\ProductRead;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\DuplicateProductCodeException;
use Siroko\Cart\Domain\Repository\ProductRepository;

final class CreateProductCommandHandler
{
    public function __construct(
        private readonly ProductRepository $productRepository,
    ) {}

    /**
     * The code check here gives the caller a message it can act on; the unique
     * index on `product.code` is what makes it hold under concurrency, and the
     * exception mapper answers 409 for that path too.
     *
     * @throws DuplicateProductCodeException
     */
    public function __invoke(CreateProductCommand $command): ProductRead
    {
        if ($this->productRepository->existsWithCode($command->getCode())) {
            throw DuplicateProductCodeException::forCode($command->getCode());
        }

        $product = new Product(
            $this->productRepository->nextIdentity(),
            $command->getCode(),
            $command->getName(),
            $command->getPrice(),
            $command->getQuantity(),
        );

        $this->productRepository->save($product);

        return ProductRead::fromModel($product);
    }
}
