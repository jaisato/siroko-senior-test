<?php

namespace Siroko\Cart\Domain\Repository;

use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\ValueObject\ItemId;

interface CartItemRepository
{
    /**
     * @return ItemId
     */
    public function nextIdentity(): ItemId;

    /**
     * @param CartItem $item
     * @return void
     */
    public function save(CartItem $item): void;

    /**
     * @param ItemId $id
     * @return CartItem|null
     */
    public function ofId(ItemId $id): ?CartItem;

    /**
     * Carga la línea bloqueando su fila, para una escritura que depende de que
     * siga existiendo.
     *
     * Dos DELETE simultáneos de la misma línea leían los dos que estaba ahí y
     * pendiente, así que los dos devolvían stock; el segundo borrado ya no
     * afectaba a ninguna fila -y Doctrine no lo considera un error-, de modo
     * que su incremento se confirmaba igual y aparecía una unidad de la nada.
     * Es el mismo fallo que la comprobación de pertenencia evita para las
     * peticiones repetidas, sólo que llegando por concurrencia.
     *
     * Con la fila bloqueada, la segunda transacción espera a que la primera
     * confirme y entonces ya no encuentra la línea, que es exactamente el caso
     * "no hay nada que devolver".
     *
     * Requiere una transacción abierta.
     */
    public function ofIdForUpdate(ItemId $id): ?CartItem;
}
