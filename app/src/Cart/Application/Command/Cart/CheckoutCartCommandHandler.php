<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Psr\Clock\ClockInterface;
use Siroko\Cart\Application\Dto\Cart\CheckoutRead;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Event\CartCheckedOut;
use Siroko\Cart\Domain\Event\DomainEventPublisher;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\EmptyCartException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\OrderRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class CheckoutCartCommandHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly OrderRepository $orderRepository,
        private readonly TransactionalSession $session,
        private readonly ClockInterface $clock,
        private readonly DomainEventPublisher $events,
    ) {}

    /**
     * Pasa el carrito a pagado y deja constancia del pedido.
     *
     * Comprobar el estado y escribirlo van en una transacción y sobre la fila
     * bloqueada. Leyendo sin bloqueo, dos checkouts simultáneos leían los dos
     * el carrito pendiente y los dos lo cobraban; y peor, un DELETE de línea
     * en marcha también lo leía pendiente, así que el checkout confirmaba un
     * carrito pagado que aún contenía la línea y el borrado devolvía después
     * al stock una unidad ya vendida.
     *
     * The order is written in the same transaction as the status change: a
     * paid cart without its order, or an order for a cart still pending, is a
     * state nothing downstream can make sense of. The `CartCheckedOut` event
     * is published inside the transaction too; the command bus middleware
     * collects it and hands it to the queue only once the handler has
     * returned, so a rolled-back checkout announces nothing.
     *
     * @throws CartNotFoundException
     * @throws InvalidCartStatusException when the cart was already checked out
     * @throws EmptyCartException         when the cart has no lines
     */
    public function __invoke(CheckoutCartCommand $command): CheckoutRead
    {
        return $this->session->executeAtomically(function () use ($command): CheckoutRead {
            $cart = $this->cartRepository->ofIdForUpdate($command->cartId());

            if (null === $cart) {
                throw CartNotFoundException::withId($command->cartId());
            }

            // The entity refuses to be paid twice, and to be paid for nothing;
            // the mapper turns both refusals into a 409.
            $cart->pay();

            $order = Order::place($this->orderRepository->nextIdentity(), $cart, $this->clock->now());

            $this->cartRepository->save($cart);
            $this->orderRepository->save($order);

            $this->events->publish(CartCheckedOut::fromOrder($order));

            return CheckoutRead::fromModels($cart, $order);
        });
    }
}
