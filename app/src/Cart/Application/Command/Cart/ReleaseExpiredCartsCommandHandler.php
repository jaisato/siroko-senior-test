<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Psr\Clock\ClockInterface;
use Siroko\Cart\Application\Dto\Cart\ReleasedCarts;
use Siroko\Cart\Application\Service\CartCancellation;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;
use Siroko\Cart\Domain\ValueObject\CartId;

final class ReleaseExpiredCartsCommandHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly CartCancellation $cancellation,
        private readonly TransactionalSession $session,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * One transaction per cart, not one for the batch: a batch of a hundred
     * carts holding locks on a hundred rows and every product they reference
     * would stall the shop while it ran, and a failure on the last cart would
     * undo the ninety-nine before it.
     *
     * The list of candidates is read without locks and each cart is then
     * loaded again with its row locked and re-checked, so a cart that was
     * checked out or already released since the list was taken is skipped.
     * That is what makes two sweeps running at once, or a sweep racing a
     * customer, harmless.
     */
    public function __invoke(ReleaseExpiredCartsCommand $command): ReleasedCarts
    {
        $now = $this->clock->now();
        $candidates = $this->cartRepository->expiredPendingIds($now, $command->batchSize);
        $released = 0;

        foreach ($candidates as $cartId) {
            if ($this->release($cartId, $now)) {
                ++$released;
            }
        }

        return new ReleasedCarts(\count($candidates), $released);
    }

    private function release(CartId $cartId, \DateTimeImmutable $now): bool
    {
        return $this->session->executeAtomically(function () use ($cartId, $now): bool {
            $cart = $this->cartRepository->ofIdForUpdate($cartId);

            if (null === $cart || !$cart->isExpiredAt($now)) {
                return false;
            }

            $this->cancellation->cancel($cart);

            return true;
        });
    }
}
