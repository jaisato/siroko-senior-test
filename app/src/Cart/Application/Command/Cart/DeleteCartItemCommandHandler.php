<?php

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;
use Siroko\Cart\Domain\ValueObject\CartStatus;

class DeleteCartItemCommandHandler
{
    public function  __construct(
        private readonly CartRepository $cartRepository,
        private readonly CartItemRepository $cartItemRepository,
        private readonly ProductRepository $productRepository,
        private readonly TransactionalSession $session,
    ) {
    }

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
     * @param DeleteCartItemCommand $command
     * @return void
     */
    public function __invoke(DeleteCartItemCommand $command): void
    {
        $this->session->executeAtomically(function () use ($command): void {
            $item = $this->cartItemRepository->ofIdForUpdate($command->itemId());

            // La línea se carga con su fila bloqueada. Dos DELETE simultáneos
            // de la misma línea leían los dos que estaba ahí y pendiente, así
            // que los dos devolvían stock; el segundo borrado ya no afectaba a
            // ninguna fila -Doctrine no lo trata como error- y su incremento se
            // confirmaba igual, sacando una unidad de la nada. Es el mismo caso
            // que la comprobación de pertenencia evita para un DELETE repetido,
            // llegando por concurrencia en vez de por reintento.
            //
            // Only give the unit back for an item that really is in this cart
            // and whose cart is still pending. Skipping the ownership check
            // would let a repeated or mistargeted delete mint stock out of
            // nothing, which is the same bug in the opposite direction; and
            // once a cart is paid the unit is sold rather than reserved, so it
            // is not ours to return.
            //
            // La comprobación va dentro de la transacción, junto a la
            // escritura: leer el estado del carrito fuera dejaba una ventana
            // para que se pagara entre la lectura y la devolución.
            if ($item !== null
                && $item->getCart()->id()->toString() === $command->cartId()->toString()
                && $item->getCart()->status()->toInt() === CartStatus::PENDING
            ) {
                $this->productRepository->returnStock($item->getProduct()->id(), 1);
            }

            $this->cartRepository->removeItem($command->cartId(), $command->itemId());
        });
    }
}
