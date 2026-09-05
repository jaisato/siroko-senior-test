<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Application\Dto\Cart\CartRead;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class CheckoutCartCommandHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly TransactionalSession $session,
    ) {}

    /**
     * Pasa el carrito a pagado.
     *
     * Comprobar el estado y escribirlo van en una transacción y sobre la fila
     * bloqueada. Leyendo sin bloqueo, dos checkouts simultáneos leían los dos
     * el carrito pendiente y los dos lo cobraban; y peor, un DELETE de línea
     * en marcha también lo leía pendiente, así que el checkout confirmaba un
     * carrito pagado que aún contenía la línea y el borrado devolvía después
     * al stock una unidad ya vendida.
     *
     * @throws CartNotFoundException
     * @throws InvalidCartStatusException when the cart was already checked out
     */
    public function __invoke(CheckoutCartCommand $command): CartRead
    {
        return $this->session->executeAtomically(function () use ($command): CartRead {
            $cart = $this->cartRepository->ofIdForUpdate($command->cartId());

            if (null === $cart) {
                throw CartNotFoundException::withId($command->cartId());
            }

            // The entity refuses to be paid twice; the mapper turns that
            // refusal into a 409 rather than the 500 a bare LogicException got.
            $cart->pay();

            $this->cartRepository->save($cart);

            return CartRead::fromModel($cart);
        });
    }
}
