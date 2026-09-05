<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Exception\CartItemNotFoundException;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class DeleteCartItemCommandHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly CartItemRepository $cartItemRepository,
        private readonly ProductRepository $productRepository,
        private readonly TransactionalSession $session,
    ) {}

    /**
     * Removes an item from a cart and gives its reserved unit back to the
     * product.
     *
     * Adding a product takes a unit off its stock, and removing the item used
     * to drop the row without putting that unit back - so every add/remove
     * cycle destroyed one unit of inventory. A shopper filling and emptying a
     * cart, or any client retrying a request, walked stock down to zero with
     * nothing sold, and the product then reported itself out of stock for
     * everyone.
     *
     * Las dos escrituras van en una transacción. Cada repositorio hace su
     * propio flush y el bus de escritura sólo lleva
     * `tactician.middleware.doctrine_rollback_only`, que marca para rollback
     * una transacción abierta pero no abre ninguna: sin envolverlas, devolver
     * el stock confirmaba por su cuenta y, si el borrado de la línea fallaba
     * después, quedaba la línea en el carrito con la unidad ya devuelta -y el
     * reintento la devolvía otra vez-.
     *
     * Dentro de la transacción se bloquean el carrito y la línea, en ese orden.
     * El orden importa: el checkout bloquea sólo el carrito, así que tomando
     * siempre antes el del carrito no hay ciclo de espera entre las dos
     * operaciones.
     *
     * Every way the request can be wrong now has an answer. It used to be 204
     * for an unknown cart, an unknown item and an item of somebody else's
     * cart alike, so a client could not tell a successful removal from a typo,
     * and a retry after a network failure looked exactly like the first call
     * regardless of whether the first one had happened.
     *
     * @throws CartNotFoundException
     * @throws InvalidCartStatusException when the cart is no longer pending
     * @throws CartItemNotFoundException  also when the item belongs to another cart
     */
    public function __invoke(DeleteCartItemCommand $command): void
    {
        $this->session->executeAtomically(function () use ($command): void {
            // Primero el carrito, después la línea. Ese orden es el que evita
            // el interbloqueo: cualquier operación que toque los dos toma
            // siempre antes el del carrito, así que no hay ciclo de espera.
            $cart = $this->cartRepository->ofIdForUpdate($command->cartId());

            if (null === $cart) {
                throw CartNotFoundException::withId($command->cartId());
            }

            // El estado se lee del carrito bloqueado, no del que cuelga de la
            // línea: bloquear sólo la línea no serializa nada frente al
            // checkout, que ni siquiera la mira. Los dos podían leer el carrito
            // pendiente a la vez, el checkout confirmar un carrito pagado que
            // todavía contenía la línea, y este handler devolver después el
            // stock de una unidad ya vendida. Once a cart is paid the unit is
            // sold rather than reserved, so it is not ours to return - and the
            // line is not ours to remove either: same 409 as the other writes.
            $cart->ensurePending();

            // La línea se carga con su fila bloqueada. Dos DELETE simultáneos
            // de la misma línea leían los dos que estaba ahí y pendiente, así
            // que los dos devolvían stock; el segundo borrado ya no afectaba a
            // ninguna fila -Doctrine no lo trata como error- y su incremento se
            // confirmaba igual, sacando una unidad de la nada. Con el bloqueo,
            // la segunda transacción ya no encuentra la línea y responde 404.
            $item = $this->cartItemRepository->ofIdForUpdate($command->itemId());

            // An item of another cart is "not found" from this cart's point of
            // view. Crediting stock for it would let a mistargeted delete mint
            // inventory out of nothing.
            if (null === $item || !$item->belongsTo($cart)) {
                throw CartItemNotFoundException::inCart($command->itemId(), $command->cartId());
            }

            $this->productRepository->returnStock($item->getProduct()->id(), 1);

            $this->cartRepository->removeItem($command->cartId(), $command->itemId());
        });
    }
}
