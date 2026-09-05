<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Repository;

use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * Products withdrawn from the catalogue (soft-deleted) are invisible to every
 * read here except through the associations that already hold them (cart
 * lines, order snapshots); writing through save() is how they are withdrawn.
 */
interface ProductRepository
{
    public function nextIdentity(): ProductId;

    public function save(Product $product): void;

    /**
     * A product in the catalogue; null for an unknown or withdrawn id.
     */
    public function ofId(ProductId $id): ?Product;

    /**
     * Like ofId(), with the row locked for a write that depends on the
     * product's current state. Requires an open transaction.
     */
    public function ofIdForUpdate(ProductId $id): ?Product;

    /**
     * A product in the catalogue by its code; null for an unknown or withdrawn code.
     */
    public function ofCode(ProductCode $code): ?Product;

    /**
     * Whether a product already carries this code - withdrawn ones included,
     * since a code stays taken. `$except` leaves one product out, for a product
     * keeping its own code through an update. The database enforces the
     * uniqueness as well; this is the check that lets the handler answer with
     * a domain exception instead of a driver error.
     */
    public function existsWithCode(ProductCode $code, ?ProductId $except = null): bool;

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
     * Sets the available stock to an exact figure, in one UPDATE. Used by the
     * catalogue (a recount), never by the cart, whose movements are relative.
     *
     * @return bool false when no such product is in the catalogue
     */
    public function setStock(ProductId $id, Quantity $quantity): bool;

    /**
     * One page of the catalogue, ordered by name. Equivalent to
     * search(ProductCriteria::all(), ...).
     *
     * @param positive-int $pageNumber 1-based
     * @param positive-int $pageSize
     *
     * @return list<Product>
     */
    public function findAll(int $pageNumber, int $pageSize): array;

    /**
     * @return int<0, max>
     */
    public function countAll(): int;

    /**
     * One page of the products matching the criteria, in its order.
     *
     * @param positive-int $pageNumber 1-based
     * @param positive-int $pageSize
     *
     * @return list<Product>
     */
    public function search(ProductCriteria $criteria, int $pageNumber, int $pageSize): array;

    /**
     * @return int<0, max>
     */
    public function countMatching(ProductCriteria $criteria): int;
}
