<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Repository;

use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\ItemId;

interface CartRepository
{
    public function nextIdentity(): CartId;

    public function save(Cart $cart): void;

    public function ofId(CartId $id): ?Cart;

    /**
     * Carga el carrito bloqueando su fila, para decidir sobre su estado.
     *
     * El estado del carrito lo leen dos operaciones que escriben a partir de
     * él: borrar una línea -que sólo devuelve stock si sigue pendiente- y el
     * checkout -que lo pasa a pagado-. La transacción por sí sola no las
     * serializa: las dos podían leerlo pendiente, el checkout confirmar un
     * carrito pagado que aún contenía la línea, y el borrado devolver después
     * el stock de una unidad ya vendida y quitarla del carrito pagado.
     *
     * Bloqueando la fila, la segunda espera y vuelve a leer el estado real.
     *
     * Requiere una transacción abierta.
     */
    public function ofIdForUpdate(CartId $id): ?Cart;

    /**
     * Elimina un item del cart dado su itemId
     */
    public function removeItem(CartId $cartId, ItemId $itemId): void;
}
