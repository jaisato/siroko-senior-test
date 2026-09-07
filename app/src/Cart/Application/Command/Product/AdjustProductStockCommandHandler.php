<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Product;

use Siroko\Cart\Application\Dto\Product\ProductRead;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\OutOfStockException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class AdjustProductStockCommandHandler
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly TransactionalSession $session,
    ) {}

    /**
     * Every movement is one atomic UPDATE, like the cart's own: a delta goes
     * through the same conditional increment and decrement the cart uses, so
     * a write-off that would take the available count below zero is refused
     * by the database rather than by a check made a moment earlier. An
     * absolute figure is a recount and simply replaces the column.
     *
     * @throws ProductNotFoundException also for a withdrawn product
     * @throws OutOfStockException      when a negative delta exceeds the available units
     */
    public function __invoke(AdjustProductStockCommand $command): ProductRead
    {
        return $this->session->executeAtomically(function () use ($command): ProductRead {
            // Locked, like every other writer that decides something from the
            // row it is about to change. Read without the lock, a withdrawal
            // committing between the read and the movement turned a perfectly
            // good reservation into the wrong answer: `reserveStock` carries
            // `deleted_at IS NULL`, so it changed nothing, and a zero-row
            // result is read here as "not enough units" - a 409 saying the
            // stock was short about a product that had simply been taken out
            // of the catalogue, which is the 404 that follows. Holding the row
            // puts the withdrawal and the movement in one order, whichever way
            // round, so what this reads is what it writes over.
            $product = $this->productRepository->ofIdForUpdate($command->id());

            if (null === $product) {
                throw ProductNotFoundException::withId($command->id());
            }

            $quantity = $command->quantity();

            if (null !== $quantity) {
                if (!$this->productRepository->setStock($product->id(), $quantity)) {
                    throw ProductNotFoundException::withId($command->id());
                }
            } else {
                $this->applyDelta($product, $command->delta() ?? 0);
            }

            return ProductRead::fromModel($product);
        });
    }

    /**
     * @throws OutOfStockException
     */
    private function applyDelta(Product $product, int $delta): void
    {
        if ($delta > 0) {
            // addStock, not returnStock: these units were never held by
            // anybody, so they have to leave the same room a recount leaves.
            // returnStock credits a hold that is being released - what the cart
            // had reserved stops being held the moment it lands in the column,
            // so the total does not move - and taking that path here let
            // `{"delta":1}` fill the last slot a pending or paid cart was going
            // to need, after which cancelling that cart had nowhere to put its
            // unit and rolled back.
            $this->productRepository->addStock($product->id(), $delta);

            return;
        }

        $units = -$delta;

        if ($units > 0 && !$this->productRepository->reserveStock($product->id(), $units)) {
            throw new OutOfStockException(\sprintf('Stock cannot go below zero: only %d unit(s) are available, %d were to be removed.', $product->quantity()->asInt(), $units));
        }
    }
}
