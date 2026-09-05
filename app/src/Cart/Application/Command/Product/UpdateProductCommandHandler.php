<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Product;

use Siroko\Cart\Application\Dto\Product\ProductRead;
use Siroko\Cart\Domain\Exception\DuplicateProductCodeException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class UpdateProductCommandHandler
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly TransactionalSession $session,
    ) {}

    /**
     * The row is locked while it changes, so two concurrent updates apply one
     * after the other instead of the second overwriting the first with stale
     * values. A new code is checked against the catalogue first; the unique
     * index settles the race, and the mapper answers 409 for that path too.
     *
     * Cart lines and order snapshots are not touched: a line follows the
     * product (the customer sees the new price), a placed order keeps what
     * was paid.
     *
     * @throws ProductNotFoundException     also for a withdrawn product
     * @throws DuplicateProductCodeException
     */
    public function __invoke(UpdateProductCommand $command): ProductRead
    {
        return $this->session->executeAtomically(function () use ($command): ProductRead {
            $product = $this->productRepository->ofIdForUpdate($command->id());

            if (null === $product) {
                throw ProductNotFoundException::withId($command->id());
            }

            $code = $command->code();

            if (null !== $code && !$code->equals($product->code())) {
                if ($this->productRepository->existsWithCode($code, $product->id())) {
                    throw DuplicateProductCodeException::forCode($code);
                }

                $product->recode($code);
            }

            $name = $command->name();

            if (null !== $name) {
                $product->rename($name);
            }

            $price = $command->price();

            if (null !== $price) {
                $product->setPrice($price);
            }

            $this->productRepository->save($product);

            return ProductRead::fromModel($product);
        });
    }
}
