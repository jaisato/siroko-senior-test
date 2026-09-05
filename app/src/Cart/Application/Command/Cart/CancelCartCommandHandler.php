<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Application\Service\CartCancellation;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class CancelCartCommandHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly CartCancellation $cancellation,
        private readonly TransactionalSession $session,
    ) {}

    /**
     * Cancels a cart and gives every unit its lines hold back to stock, in one
     * transaction and under the cart's row lock - a checkout or a line change
     * running at the same time waits and then finds the cart canceled.
     *
     * @throws CartNotFoundException
     * @throws InvalidCartStatusException when the cart is delivered or already canceled
     */
    public function __invoke(CancelCartCommand $command): void
    {
        $this->session->executeAtomically(function () use ($command): void {
            $cart = $this->cartRepository->ofIdForUpdate($command->cartId());

            if (null === $cart) {
                throw CartNotFoundException::withId($command->cartId());
            }

            $this->cancellation->cancel($cart);
        });
    }
}
