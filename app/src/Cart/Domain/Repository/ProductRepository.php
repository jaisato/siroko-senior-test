<?php

namespace Siroko\Cart\Domain\Repository;

use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\ProductId;

interface ProductRepository
{
    /**
     * @return ProductId
     */
    public function nextIdentity(): ProductId;

    /**
     * @param Product $product
     * @return void
     */
    public function save(Product $product): void;

    /**
     * @param ProductId $id
     * @return Product|null
     */
    public function ofId(ProductId $id): ?Product;

    /**
     * Devuelve unidades al stock de forma atómica.
     *
     * No es `ofId()` + `setQuantity()` + `save()` a propósito. Ese ciclo
     * leer-modificar-escribir pierde incrementos: si se borran a la vez dos
     * líneas del mismo producto, los dos handlers leen la misma cantidad,
     * calculan el mismo valor y la segunda escritura pisa a la primera, de modo
     * que el stock sube una unidad en vez de dos y la que falta no aparece en
     * ningún sitio.
     *
     * Expresado como un único incremento, la base de datos serializa las dos
     * operaciones sobre la fila y ninguna se pierde.
     *
     * @param positive-int $units
     */
    public function returnStock(ProductId $id, int $units): void;

    /**
     * Reserva unidades de stock de forma atómica. Devuelve false si no había
     * suficientes.
     *
     * Contrapartida de `returnStock()`, y tiene que serlo: mientras el resto de
     * escrituras de stock fueran `ofId()` + `setQuantity()` + `save()`, un
     * incremento atómico no servía de nada, porque esa escritura manda un valor
     * absoluto calculado sobre una lectura vieja y borra el incremento. Con
     * stock 4: un borrado lo sube a 5 y un alta que había leído 4 lo deja en 3,
     * cuando debía quedar en 4.
     *
     * La condición `quantity >= :units` va dentro del propio UPDATE, así que
     * comprobar y restar son la misma operación. Separarlas -mirar si hay stock
     * y luego restarlo- permite que dos altas simultáneas pasen las dos la
     * comprobación y vendan más unidades de las que hay.
     *
     * @param positive-int $units
     *
     * @return bool true si se reservaron; false si no había stock suficiente
     */
    public function reserveStock(ProductId $id, int $units): bool;

    /**
     * @param int $pageNumber
     * @param int $pageSize
     * @return array|Product[]
     */
    public function findAll(int $pageNumber, int $pageSize): array;
}
