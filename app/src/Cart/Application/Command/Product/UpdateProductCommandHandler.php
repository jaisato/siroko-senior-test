<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Product;

use Siroko\Cart\Application\Dto\Product\ProductRead;
use Siroko\Cart\Domain\Exception\DuplicateProductCodeException;
use Siroko\Cart\Domain\Exception\ProductIsInAPendingCartException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class UpdateProductCommandHandler
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly CartRepository $cartRepository,
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
     * was paid. That is why the currency cannot move while a pending cart
     * holds the product - see the guard below.
     *
     * @throws ProductNotFoundException           also for a withdrawn product
     * @throws DuplicateProductCodeException
     * @throws ProductIsInAPendingCartException   when the currency would change under a cart
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
                // A cart line points at the product rather than holding a copy
                // of its price, so repricing into another currency breaks the
                // "one currency" rule of every pending cart already holding it
                // - after the fact, and with no way out for the customer:
                // subtotal() throws from then on, reading the cart answers 409
                // and checkout rolls back until the cart is cancelled or
                // expires. The amount may change freely; the currency may not,
                // while a cart is still counting on it.
                $current = $product->price()->currency();

                if ($current->getCurrencyCode() !== $price->currency()->getCurrencyCode()
                    && $this->cartRepository->anyPendingHolds($product->id())
                ) {
                    throw ProductIsInAPendingCartException::cannotChangeCurrency($current, $price->currency());
                }

                $product->setPrice($price);
            }

            $this->productRepository->save($product);

            return ProductRead::fromModel($product);
        });
    }
}
