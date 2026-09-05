<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\ProductCriteria;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ProductCodeType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ProductIdType;

final class DoctrineProductRepository implements ProductRepository
{
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

        $this->em->getConnection()->executeStatement(
            'UPDATE product SET quantity = quantity + :units WHERE id = :id',
            ['units' => $units, 'id' => $id],
            ['units' => ParameterType::INTEGER, 'id' => ProductIdType::NAME],
        );

        $this->refreshIfManaged($id);
    }

    /**
     * Un único UPDATE condicional: comprobar y restar son la misma operación,
     * de modo que dos altas simultáneas no pueden pasar las dos la comprobación
     * y vender de más. `rowCount()` distingue "reservado" de "no había stock".
     */
    public function reserveStock(ProductId $id, int $units): bool
    {
        $this->guardUnits($units);

        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE product SET quantity = quantity - :units WHERE id = :id AND quantity >= :units',
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
     */
    public function setStock(ProductId $id, Quantity $quantity): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            'UPDATE product SET quantity = :quantity WHERE id = :id AND deleted_at IS NULL',
            ['quantity' => $quantity->asInt(), 'id' => $id],
            ['quantity' => ParameterType::INTEGER, 'id' => ProductIdType::NAME],
        );

        if (1 !== $affected) {
            return false;
        }

        $this->refreshIfManaged($id);

        return true;
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
