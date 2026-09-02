<?php

namespace Siroko\Cart\Application\Command\Cart;

use Brick\Money\Exception\UnknownCurrencyException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Infrastructure\Api\Dto\Cart\CartRead;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CheckoutCartCommandHandler
{
    /**
     * @param CartRepository $cartRepository
     */
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly TransactionalSession $session,
    ) {
    }

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
     * @param CheckoutCartCommand $command
     * @return CartRead
     * @throws UnknownCurrencyException
     * @throws InvalidCartStatusException
     */
    public function __invoke(CheckoutCartCommand $command): CartRead
    {
        return $this->session->executeAtomically(function () use ($command): CartRead {
            $cart = $this->cartRepository->ofIdForUpdate($command->cartId());

            if ($cart === null) {
                throw new NotFoundHttpException("Cart not found");
            }

            if ($cart->status()->toInt() !== CartStatus::PENDING) {
                // A bare LogicException carries no domain meaning, and
                // ApiExceptionMapper looks the class up exactly - so checking
                // out a cart twice took the unexpected-error path and answered
                // 500, while the 409 the mapper declares for this case never
                // fired.
                throw new InvalidCartStatusException("Cart is not pending");
            }

            $cart->setStatus(new CartStatus(CartStatus::PAID));

            $this->cartRepository->save($cart);

            return CartRead::fromModel($cart);
        });
    }
}
