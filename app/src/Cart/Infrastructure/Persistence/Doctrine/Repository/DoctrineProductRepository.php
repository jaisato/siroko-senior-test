<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

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
