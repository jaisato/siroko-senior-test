<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
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

    public function existsWithCode(ProductCode $code): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->where('p.code = :code')
            ->setParameter('code', $code, ProductCodeType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
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
        $product = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $id, ProductIdType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $product instanceof Product ? $product : null;
    }

    /**
     * The bounds of a page are the query's business (GetProductListQuery);
     * the clamp that used to live here as well meant two places disagreeing on
     * what a valid page is.
     *
     * @return list<Product>
     */
    public function findAll(int $pageNumber, int $pageSize): array
    {
        /** @var list<Product> $products */
        $products = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($pageNumber - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return $products;
    }

    public function countAll(): int
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Product::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();

        return max(0, (int) $count);
    }
}
