<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ProductIdType;

class DoctrineProductRepository implements ProductRepository
{
    /**
     * @param EntityManagerInterface $em
     */
    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    /**
     * @return ProductId
     */
    public function nextIdentity(): ProductId
    {
        return ProductId::fromString(Uuid::uuid7()->toString());
    }

    /**
     * @param Product $product
     * @return void
     */
    public function save(Product $product): void
    {
        $this->em->persist($product);
        $this->em->flush();
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
            ['units' => ParameterType::INTEGER, 'id' => ProductIdType::NAME]
        );
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
            ['units' => ParameterType::INTEGER, 'id' => ProductIdType::NAME]
        );

        return $affected === 1;
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
            throw new \InvalidArgumentException(
                sprintf('Stock movements need at least one unit, got %d.', $units)
            );
        }
    }

    /**
     * @param ProductId $id
     * @return Product|null
     */
    public function ofId(ProductId $id): ?Product
    {
        // return $this->em->find(Product::class, $id);

        $qb = $this->em->createQueryBuilder();

        $qb->select('p')
            ->from(Product::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $id, ProductIdType::NAME);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @param int $pageNumber
     * @param int $pageSize
     * @return array|Product[]
     */
    public function findAll(int $pageNumber, int $pageSize): array
    {
        $page     = max(1, $pageNumber);
        $pageSize = max(1, min(100, $pageSize));
        $offset   = ($page - 1) * $pageSize;

        $qb = $this->em->createQueryBuilder();

        $qb->select('p')
            ->from(Product::class, 'p')
            ->orderBy('p.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($pageSize);

        return $qb->getQuery()->getResult();
    }
}
