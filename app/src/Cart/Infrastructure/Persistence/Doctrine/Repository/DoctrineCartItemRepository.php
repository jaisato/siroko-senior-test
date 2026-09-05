<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ItemIdType;

final class DoctrineCartItemRepository implements CartItemRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function nextIdentity(): ItemId
    {
        return ItemId::fromString(Uuid::uuid7()->toString());
    }

    public function save(CartItem $item): void
    {
        $this->em->persist($item);
        $this->em->flush();
    }

    public function ofId(ItemId $id): ?CartItem
    {
        $item = $this->em->createQueryBuilder()
            ->select('c')
            ->from(CartItem::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id, ItemIdType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $item instanceof CartItem ? $item : null;
    }

    public function ofIdForUpdate(ItemId $id): ?CartItem
    {
        $item = $this->em->createQueryBuilder()
            ->select('c')
            ->from(CartItem::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id, ItemIdType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $item instanceof CartItem ? $item : null;
    }
}
