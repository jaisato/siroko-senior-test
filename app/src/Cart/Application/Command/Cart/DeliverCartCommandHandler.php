<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Application\Dto\Cart\CartRead;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class DeliverCartCommandHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly TransactionalSession $session,
    ) {}

    /**
     * Marks a paid cart as delivered. Read and written under the row lock,
     * like every other status change, so that it cannot race a cancellation.
     *
     * @throws CartNotFoundException
     * @throws InvalidCartStatusException when the cart is not paid
     */
    public function __invoke(DeliverCartCommand $command): CartRead
    {
        return $this->session->executeAtomically(function () use ($command): CartRead {
            $cart = $this->cartRepository->ofIdForUpdate($command->cartId());

            if (null === $cart) {
                throw CartNotFoundException::withId($command->cartId());
            }

            $cart->deliver();

            $this->cartRepository->save($cart);

            return CartRead::fromModel($cart);
        });
    }
}
