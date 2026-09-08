<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Application\Dto\Cart\CartRead;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Exception\CartItemNotFoundException;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Exception\OutOfStockException;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class ChangeCartItemQuantityCommandHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly CartItemRepository $cartItemRepository,
        private readonly ProductRepository $productRepository,
        private readonly TransactionalSession $session,
    ) {}

    /**
     * Moves a line to the requested number of units and settles the difference
     * with the stock in the same transaction: more units means reserving the
     * extra ones, fewer means giving the surplus back, zero means the line goes
     * and every unit it held returns to the shelf.
     *
     * The locks are taken in the order every other cart write takes them -
     * cart, then line, then product (the stock UPDATE locks the product row).
     * Any other order would deadlock against an add or a removal running on
     * the same cart.
     *
     * The delta is computed from the line as read under the lock, so two
     * concurrent changes of the same line serialise and each one settles
     * exactly the units it moved.
     *
     * @throws CartNotFoundException
     * @throws InvalidCartStatusException when the cart is no longer pending
     * @throws CartItemNotFoundException  also when the line belongs to another cart
     * @throws OutOfStockException        when the extra units are not available
     * @throws InvalidQuantityException   when the quantity is not one a line accepts
     */
    public function __invoke(ChangeCartItemQuantityCommand $command): CartRead
    {
        $cart = $this->session->executeAtomically(function () use ($command): Cart {
            $cart = $this->cartRepository->ofIdForUpdate($command->cartId());

            if (null === $cart) {
                throw CartNotFoundException::withId($command->cartId());
            }

            $cart->ensureAccessibleBy($command->customer());
            $cart->ensurePending();

            $item = $this->cartItemRepository->ofIdForUpdate($command->itemId());

            if (null === $item || !$item->belongsTo($cart)) {
                throw CartItemNotFoundException::inCart($command->itemId(), $command->cartId());
            }

            if ($command->removesTheLine()) {
                $this->remove($cart, $item);
            } else {
                $this->settle($item, $item->changeQuantity($command->quantity()));
            }

            $this->cartRepository->save($cart);

            return $cart;
        });

        return CartRead::fromModel($cart);
    }

    private function remove(Cart $cart, CartItem $item): void
    {
        $this->productRepository->returnStock($item->getProduct()->id(), $item->units());

        $this->cartRepository->removeItem($cart->id(), $item->id());
    }

    /**
     * @param int $delta units to reserve (positive) or to give back (negative); zero moves nothing
     */
    private function settle(CartItem $item, int $delta): void
    {
        if ($delta > 0 && !$this->productRepository->reserveStock($item->getProduct()->id(), $delta)) {
            throw new OutOfStockException(\sprintf('Product %s does not have %d more units available', $item->getProduct()->id()->toString(), $delta));
        }

        $surplus = -$delta;

        if ($surplus > 0) {
            $this->productRepository->returnStock($item->getProduct()->id(), $surplus);
        }
    }
}
