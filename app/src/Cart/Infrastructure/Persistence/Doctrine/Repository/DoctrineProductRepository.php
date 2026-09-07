<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidStockAdjustmentException;
use Siroko\Cart\Domain\Repository\ProductCriteria;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ProductCodeType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ProductIdType;

final class DoctrineProductRepository implements ProductRepository
{
    /**
     * The cart states whose units come back to the product if the cart is
     * called off - which is what `Cart::cancel()` accepts, and what
     * `CartCancellation` returns stock for. A delivered cart's units are gone
     * and a canceled one has already given them back.
     *
     * @var list<int>
     */
    private const array REFUNDABLE_STATUSES = [CartStatus::PENDING, CartStatus::PAID];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function nextIdentity(): ProductId
    {
        return ProductId::fromString(Uuid::uuid7()->toString());
    }

    public function save(Product $product): void
    {
        $this->em->persist($product);
        $this->em->flush();
    }

    public function existsWithCode(ProductCode $code, ?ProductId $except = null): bool
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->where('p.code = :code')
            ->setParameter('code', $code, ProductCodeType::NAME);

        if (null !== $except) {
            $qb->andWhere('p.id <> :except')->setParameter('except', $except, ProductIdType::NAME);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Un único UPDATE, para que dos borrados simultáneos del mismo producto no
     * se pisen el incremento. `quantity` es una columna INT, así que la suma se
     * hace en la base de datos; el id va con su tipo registrado para que se
     * convierta a los 16 bytes con los que está guardado.
     *
     * Se salta la entidad a propósito: un incremento atómico no puede pasar por
     * el objeto en memoria. No pone en riesgo la invariante de `Quantity`, que
     * es no ser negativa, porque esto sólo suma.
     */
    public function returnStock(ProductId $id, int $units): void
    {
        $this->guardUnits($units);

        // Sin `deleted_at IS NULL`, a diferencia de la reserva: las unidades que
        // un carrito soltó son suyas de devolver aunque el producto se haya
        // retirado del catálogo entre medias. Retenerlas no las vende a nadie.
        //
        // El techo va en el mismo UPDATE por el mismo motivo que el suelo de la
        // reserva: `quantity` es un INT con signo y la suma se hace en la base
        // de datos, así que sin la condición un `delta` positivo sobre un
        // producto ya en el máximo se sale del rango de la columna y MySQL
        // responde con un error que el cliente ve como un 500.
        $applied = $this->em->getConnection()->executeStatement(
            'UPDATE product SET quantity = quantity + :units WHERE id = :id AND quantity <= :max - :units',
            ['units' => $units, 'id' => $id, 'max' => Quantity::MAX_QUANTITY],
            ['units' => ParameterType::INTEGER, 'id' => ProductIdType::NAME, 'max' => ParameterType::INTEGER],
        );

        // Cero filas puede ser "no existe" —devolver stock a un producto que ya
        // no está es un no-op tolerado— o "no cabe", que sí hay que decirlo:
        // callar perdería unidades de inventario.
        if (0 === $applied) {
            $this->refuseIfItWouldOverflow($id, $units);
        }

        $this->refreshIfManaged($id);
    }

    public function addStock(ProductId $id, int $units, int $heldUnits): void
    {
        $this->guardUnits($units);

        // El mismo incremento atómico que `returnStock()`, con el techo del
        // producto en lugar del de la columna: estas unidades no las tenía
        // retenidas nadie, así que lo disponible más lo retenido tiene que
        // caber bajo el máximo -exactamente lo que comprueba un recuento
        // absoluto-. Sin esto, `{"delta":1}` sobre un producto a una unidad del
        // máximo con un carrito reteniendo una pasaba, y cancelar ese carrito
        // después se quedaba sin sitio donde devolver la suya y tiraba abajo la
        // cancelación entera.
        //
        // Lo retenido llega como número, no como subconsulta. Leerlo aquí
        // dentro habría sido leer -y bloquear- filas de carrito con la del
        // producto ya tomada, que es el orden contrario al que toma una
        // cancelación: las dos transacciones se esperaban la una a la otra,
        // MySQL mataba una y el bus de escritura no reintenta.
        $applied = $this->em->getConnection()->executeStatement(
            'UPDATE product SET quantity = quantity + :units WHERE id = :id AND quantity <= :max - :units - :held',
            ['units' => $units, 'id' => $id, 'max' => Quantity::MAX_QUANTITY, 'held' => $heldUnits],
            [
                'units' => ParameterType::INTEGER,
                'id' => ProductIdType::NAME,
                'max' => ParameterType::INTEGER,
                'held' => ParameterType::INTEGER,
            ],
        );

        if (0 === $applied) {
            $current = $this->quantityOf($id);

            // Como en `returnStock()`: cero filas puede ser "no existe" -sumar
            // a un producto que ya no está es un no-op tolerado- o "no cabe",
            // que sí hay que decir en voz alta, y por cuál de los dos techos.
            if (null !== $current) {
                $this->refuseIfItWouldOverflow($id, $units);
                $this->refuseIfItLeavesNoRoomForHeldUnits($current + $units, $heldUnits);
            }
        }

        $this->refreshIfManaged($id);
    }

    /** Lo que la fila tiene ahora mismo, o null si el producto ya no está. */
    private function quantityOf(ProductId $id): ?int
    {
        $current = $this->em->getConnection()->fetchOne(
            'SELECT quantity FROM product WHERE id = :id',
            ['id' => $id],
            ['id' => ProductIdType::NAME],
        );

        return is_numeric($current) ? (int) $current : null;
    }

    /**
     * @throws InvalidStockAdjustmentException si el producto existe y sumar
     *                                         `$units` pasaría del máximo
     */
    private function refuseIfItWouldOverflow(ProductId $id, int $units): void
    {
        $current = $this->em->getConnection()->fetchOne(
            'SELECT quantity FROM product WHERE id = :id',
            ['id' => $id],
            ['id' => ProductIdType::NAME],
        );

        if (false === $current) {
            return; // El producto no está; no había nada que incrementar.
        }

        if ((int) $current > Quantity::MAX_QUANTITY - $units) {
            throw InvalidStockAdjustmentException::wouldExceedMaximum(Quantity::MAX_QUANTITY);
        }
    }

    /**
     * Un único UPDATE condicional: comprobar y restar son la misma operación,
     * de modo que dos altas simultáneas no pueden pasar las dos la comprobación
     * y vender de más. `rowCount()` distingue "reservado" de "no había stock".
     *
     * `deleted_at IS NULL` está en el mismo UPDATE por el mismo motivo: si la
     * retirada del catálogo se comprueba en una lectura anterior, una baja que
     * se confirme entre esa lectura y esta resta deja reservadas unidades de un
     * producto que ya no se vende. Reservar sobre un producto retirado devuelve
     * false, igual que quedarse sin stock; el 409 que ve el cliente es el mismo.
     */
    public function reserveStock(ProductId $id, int $units): bool
    {
        $this->guardUnits($units);

        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE product SET quantity = quantity - :units WHERE id = :id AND quantity >= :units AND deleted_at IS NULL',
            ['units' => $units, 'id' => $id],
            ['units' => ParameterType::INTEGER, 'id' => ProductIdType::NAME],
        );

        if (1 !== $affected) {
            return false;
        }

        $this->refreshIfManaged($id);

        return true;
    }

    /**
     * A recount: the column is replaced, in one statement, for a product that
     * is still in the catalogue.
     *
     * Unlike reserveStock(), the new figure may be the one the column already
     * holds - a recount that confirms the count is the most ordinary recount
     * there is. MySQL reports *changed* rows, not matched ones, so that
     * statement affects nothing, and reading zero as "no such product"
     * answered 404 for a product sitting right there. SQLite counts the rows
     * the statement touched, so the local suite never saw it. Asking whether
     * the row is in the catalogue settles it the same way on both.
     *
     * What refundable carts hold arrives as a number for the reason addStock()
     * spells out: reading it here would lock cart rows with this product's own
     * row already taken, the opposite order from a cancellation, and the two
     * deadlocked.
     */
    public function setStock(ProductId $id, Quantity $quantity, int $heldUnits): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE product SET quantity = :quantity WHERE id = :id AND deleted_at IS NULL AND :quantity <= :max - :held',
            ['quantity' => $quantity->asInt(), 'id' => $id, 'max' => Quantity::MAX_QUANTITY, 'held' => $heldUnits],
            [
                'quantity' => ParameterType::INTEGER,
                'id' => ProductIdType::NAME,
                'max' => ParameterType::INTEGER,
                'held' => ParameterType::INTEGER,
            ],
        );

        if (1 === $affected) {
            $this->refreshIfManaged($id);

            return true;
        }

        if (!$this->isInCatalogue($id)) {
            return false;
        }

        // In the catalogue and nothing changed: either the figure is the one
        // the column already holds - the most ordinary recount there is - or it
        // is one the condition above refused.
        $this->refuseIfItLeavesNoRoomForHeldUnits($quantity->asInt(), $heldUnits);

        $this->refreshIfManaged($id);

        return true;
    }

    /**
     * A recount says what is *available*. The units carts are holding are on
     * top of it, and they come back to this column when a line is removed or
     * the cart is called off.
     *
     * Recounted to the maximum with holds outstanding, that return had nowhere
     * to go: returnStock() refused it - rightly, since `quantity` is an INT and
     * silence would lose inventory - the cart transition rolled back with it,
     * and `cart:release-expired` met the same cart on every run and stopped
     * there. So the ceiling belongs to the product, not to the column: what is
     * available plus what is held has to fit under it.
     *
     * @throws InvalidStockAdjustmentException
     */
    private function refuseIfItLeavesNoRoomForHeldUnits(int $available, int $held): void
    {
        if ($available > Quantity::MAX_QUANTITY - $held) {
            throw InvalidStockAdjustmentException::leavesNoRoomForHeldUnits($held, Quantity::MAX_QUANTITY);
        }
    }

    /**
     * Units of this product held by a cart whose cancellation would credit them
     * back.
     *
     * Which is not only the pending ones: `Cart::cancel()` takes a paid cart
     * too - that is what a refund is - and `CartCancellation` returns the
     * units of whatever it cancels. Counting only the pending carts left the
     * paid ones' units outside the ceiling, so a recount to the maximum passed
     * and calling off the paid cart afterwards was refused with nowhere to put
     * them, taking the cancellation down with it. A delivered or already
     * canceled cart credits nothing, so neither is counted.
     *
     * It takes no locks, and that is the point rather than an omission. Two
     * arrangements that do were tried and both deadlock. Inside the movement's
     * own UPDATE, as a subquery, the cart rows are read *as a locking read* -
     * that is what InnoDB does with a subquery of a data-changing statement
     * under REPEATABLE READ - so the adjustment held the product and reached
     * for the carts while a cancellation held its cart and reached for the
     * product. Moved out and taken `FOR SHARE` before the product lock, the
     * equality on `cart_item.product_id` holds the *gap*, and adding a line for
     * this product is an insert into it: the adjustment then held the gap and
     * waited for the product, while an add held the product and waited for the
     * gap. A cycle either way round, and the write bus does not retry a
     * deadlock victim.
     *
     * A plain read has neither problem, and the caller's own lock on the
     * product row is what makes the number safe to use afterwards: any cart
     * operation that changes what is held changes the available count by the
     * same units in the opposite direction, and that writes this product's row.
     * So while the caller holds it, nothing can move this figure -except a
     * delivery, which only lowers it, and a lower figure only makes the ceiling
     * stricter-.
     *
     * Read *after* that lock, and by a transaction that has read nothing
     * consistently before: under REPEATABLE READ the snapshot is created by the
     * first non-locking read, and a locking one -the caller's- does not create
     * it. So this statement opens the snapshot itself, and sees every cart
     * operation that committed before the lock was taken.
     */
    public function unitsHeldInRefundableCarts(ProductId $id): int
    {
        $held = $this->em->getConnection()->fetchOne(
            <<<'SQL'
                SELECT COALESCE(SUM(i.quantity), 0)
                  FROM cart_item i
                  JOIN cart c ON c.id = i.cart_id
                 WHERE i.product_id = :id AND c.status IN (:refundable)
                SQL,
            ['id' => $id, 'refundable' => self::REFUNDABLE_STATUSES],
            ['id' => ProductIdType::NAME, 'refundable' => ArrayParameterType::INTEGER],
        );

        return is_numeric($held) ? (int) $held : 0;
    }

    /**
     * Whether the product is there and has not been withdrawn - the question
     * a zero-row UPDATE leaves unanswered.
     */
    private function isInCatalogue(ProductId $id): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM product WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
            ['id' => ProductIdType::NAME],
        );
    }

    /**
     * The raw UPDATE bypasses the unit of work, so a Product already loaded in
     * this request kept its old quantity in memory. Nothing wrote that stale
     * value back - Doctrine only flushes what changed in PHP - but anything
     * that read the entity after the movement saw stock that was no longer
     * there. Reloading the managed instance keeps the object truthful.
     */
    private function refreshIfManaged(ProductId $id): void
    {
        $product = $this->em->getUnitOfWork()->tryGetById(['id' => $id], Product::class);

        if ($product instanceof Product) {
            $this->em->refresh($product);
        }
    }

    /**
     * Las dos operaciones de stock declaran `positive-int` y el SQL cuenta con
     * ello. Con 0 unidades el UPDATE no cambia ninguna fila, y `rowCount()` a 0
     * es indistinguible de "no había stock", así que una reserva de 0 se
     * reportaba como falta de stock sobre un producto disponible. Con unidades
     * negativas es peor: `quantity >= -5` se cumple siempre y la resta suma,
     * de modo que una reserva inventaría stock y además diría que fue bien.
     *
     * Ninguno de los dos casos es una petición del cliente -las entradas se
     * validan antes-, sino un error de programación, y como tal se señala.
     */
    private function guardUnits(int $units): void
    {
        if ($units < 1) {
            throw new \InvalidArgumentException(\sprintf('Stock movements need at least one unit, got %d.', $units));
        }
    }

    public function ofId(ProductId $id): ?Product
    {
        $product = $this->inCatalogue()
            ->andWhere('p.id = :id')
            ->setParameter('id', $id, ProductIdType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $product instanceof Product ? $product : null;
    }

    public function ofIdForUpdate(ProductId $id): ?Product
    {
        $product = $this->inCatalogue()
            ->andWhere('p.id = :id')
            ->setParameter('id', $id, ProductIdType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $product instanceof Product ? $product : null;
    }

    public function ofCode(ProductCode $code): ?Product
    {
        $product = $this->inCatalogue()
            ->andWhere('p.code = :code')
            ->setParameter('code', $code, ProductCodeType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $product instanceof Product ? $product : null;
    }

    /**
     * @return list<Product>
     */
    public function findAll(int $pageNumber, int $pageSize): array
    {
        return $this->search(ProductCriteria::all(), $pageNumber, $pageSize);
    }

    public function countAll(): int
    {
        return $this->countMatching(ProductCriteria::all());
    }

    /**
     * The bounds of a page are the query's business (GetProductListQuery);
     * the clamp that used to live here as well meant two places disagreeing on
     * what a valid page is.
     *
     * @return list<Product>
     */
    public function search(ProductCriteria $criteria, int $pageNumber, int $pageSize): array
    {
        $qb = $this->matching($criteria);

        $direction = $criteria->sort->isDescending() ? 'DESC' : 'ASC';

        // Every order ends on the id so that pages never overlap on ties.
        $qb->orderBy(match ($criteria->sort->field()) {
            'name' => 'p.name',
            'price' => 'p.price.amount',
            'code' => 'p.code',
        }, $direction)->addOrderBy('p.id', $direction);

        /** @var list<Product> $products */
        $products = $qb
            ->setFirstResult(($pageNumber - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return $products;
    }

    public function countMatching(ProductCriteria $criteria): int
    {
        $count = $this->matching($criteria)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return max(0, (int) $count);
    }

    /**
     * Products still in the catalogue: withdrawn ones are invisible to every
     * read here, though the lines that reference them still load them.
     */
    private function inCatalogue(): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.deletedAt IS NULL');
    }

    /**
     * The text is matched case-insensitively against the name and the code.
     * `%` and `_` in it are escaped so that they mean themselves; the escape
     * character is declared explicitly because MySQL's default one (`\`) is
     * disabled by the NO_BACKSLASH_ESCAPES SQL mode.
     */
    private function matching(ProductCriteria $criteria): QueryBuilder
    {
        $qb = $this->inCatalogue();

        if (null !== $criteria->text) {
            $pattern = '%' . mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $criteria->text)) . '%';

            $qb->andWhere("LOWER(p.name) LIKE :text ESCAPE '!' OR LOWER(p.code) LIKE :text ESCAPE '!'")
                ->setParameter('text', $pattern);
        }

        if (null !== $criteria->minPrice) {
            $qb->andWhere('p.price.amount >= :minPrice')->setParameter('minPrice', $criteria->minPrice, Types::DECIMAL);
        }

        if (null !== $criteria->maxPrice) {
            $qb->andWhere('p.price.amount <= :maxPrice')->setParameter('maxPrice', $criteria->maxPrice, Types::DECIMAL);
        }

        if (true === $criteria->inStock) {
            $qb->andWhere('p.quantity > 0');
        } elseif (false === $criteria->inStock) {
            $qb->andWhere('p.quantity = 0');
        }

        return $qb;
    }
}
