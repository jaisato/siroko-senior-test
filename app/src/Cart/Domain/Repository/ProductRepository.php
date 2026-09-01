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
     * @param int $pageNumber
     * @param int $pageSize
     * @return array|Product[]
     */
    public function findAll(int $pageNumber, int $pageSize): array;
}
