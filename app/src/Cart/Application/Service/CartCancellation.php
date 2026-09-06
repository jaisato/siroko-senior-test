<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Service;

use Psr\Clock\ClockInterface;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\OrderRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;

/**
 * Cancels a cart and returns its reserved units to stock.
 *
 * Shared by the explicit cancellation (DELETE /v1/carts/{id}) and by the sweep
 * that releases expired reservations, so both give stock back the same way.
 *
 * The caller holds the cart's row lock and an open transaction: the status
 * change and the stock movements have to land together, or a crash between
 * them leaves units both released and reserved.
 */
final class CartCancellation
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly ProductRepository $productRepository,
        private readonly OrderRepository $orderRepository,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws InvalidCartStatusException when the cart is delivered or already canceled
     */
    public function cancel(Cart $cart): void
    {
        // Whether the cart was paid decides whether there is an order to call
        // off, and cancel() is about to change that.
        $wasPaid = $cart->status()->isPaid();

        // The transition is checked before any stock moves.
        $cart->cancel();

        foreach ($this->inLockOrder($cart) as $item) {
            $this->productRepository->returnStock($item->getProduct()->id(), $item->units());
        }

        $this->cartRepository->save($cart);

        if ($wasPaid) {
            $this->cancelOrderOf($cart);
        }
    }

    /**
     * The order is the record of what was bought, so calling the purchase off
     * has to reach it. Left standing, it was still confirmed by the queued
     * CartCheckedOut consumer - which only asked whether the confirmation had
     * already been sent - and every read of it showed a purchase the customer
     * had cancelled.
     */
    private function cancelOrderOf(Cart $cart): void
    {
        $order = $this->orderRepository->ofCart($cart->id());

        if (null === $order || !$order->cancel($this->clock->now())) {
            return;
        }

        $this->orderRepository->save($order);
    }

    /**
     * Products are credited in id order - the order every stock write takes
     * the product rows in. Two cancellations of carts sharing products, each
     * walking its lines in its own order, would otherwise wait on each other.
     *
     * @return list<CartItem>
     */
    private function inLockOrder(Cart $cart): array
    {
        $items = $cart->items()->toArray();

        usort(
            $items,
            static fn(CartItem $a, CartItem $b): int => strcmp(
                $a->getProduct()->id()->toString(),
                $b->getProduct()->id()->toString(),
            ),
        );

        return $items;
    }
}
