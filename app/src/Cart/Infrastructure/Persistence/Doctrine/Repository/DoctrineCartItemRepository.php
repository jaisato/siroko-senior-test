<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ItemIdType;

class DoctrineCartItemRepository implements CartItemRepository
{
    /**
     * @param EntityManagerInterface $em
     */
    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    /**
     * @return ItemId
     */
    public function nextIdentity(): ItemId
    {
        return ItemId::fromString(Uuid::uuid7()->toString());
    }

    /**
     * @param CartItem $item
     * @return void
     */
    public function save(CartItem $item): void
    {
        $this->em->persist($item);
        $this->em->flush();
    }

    /**
     * @param ItemId $id
     * @return CartItem|null
     */
    public function ofId(ItemId $id): ?CartItem
    {
        // return $this->em->find(Product::class, $id);

        $qb = $this->em->createQueryBuilder();

        $qb->select('c')
            ->from(CartItem::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id, ItemIdType::NAME);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
