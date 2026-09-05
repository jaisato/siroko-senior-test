<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Product;

use Psr\Clock\ClockInterface;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class DeleteProductCommandHandler
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly TransactionalSession $session,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Withdraws a product from the catalogue. The row stays: cart lines and
     * order snapshots reference it (the foreign key is RESTRICT for that very
     * reason), and its code stays taken. From then on the product is not
     * found - by id, by code, in listings, or when a cart asks for it.
     *
     * Units still held by pending carts are theirs until they are released or
     * paid; the catalogue does not reach into carts.
     *
     * @throws ProductNotFoundException also when already withdrawn
     */
    public function __invoke(DeleteProductCommand $command): void
    {
        $this->session->executeAtomically(function () use ($command): void {
            $product = $this->productRepository->ofIdForUpdate($command->id());

            if (null === $product) {
                throw ProductNotFoundException::withId($command->id());
            }

            $product->delete($this->clock->now());

            $this->productRepository->save($product);
        });
    }
}
