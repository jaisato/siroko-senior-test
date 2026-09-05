<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\CustomerId;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\CartIdType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\CartStatusType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\CustomerIdType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ItemIdType;

final class DoctrineCartRepository implements CartRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function nextIdentity(): CartId
    {
        return CartId::fromString(Uuid::uuid7()->toString());
    }

    public function save(Cart $cart): void
    {
        $this->em->persist($cart);
        $this->em->flush();
    }

    public function ofId(CartId $id): ?Cart
    {
        $cart = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Cart::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id, CartIdType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $cart instanceof Cart ? $cart : null;
    }

    public function ofIdForUpdate(CartId $id): ?Cart
    {
        $cart = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Cart::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id, CartIdType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $cart instanceof Cart ? $cart : null;
    }

    public function search(?CustomerId $owner, ?CartStatus $status, int $pageNumber, int $pageSize): array
    {
        /** @var list<Cart> $carts */
        $carts = $this->matching($owner, $status)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult(($pageNumber - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return $carts;
    }

    public function countMatching(?CustomerId $owner, ?CartStatus $status): int
    {
        $count = $this->matching($owner, $status)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return max(0, (int) $count);
    }

    private function matching(?CustomerId $owner, ?CartStatus $status): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Cart::class, 'c');

        if (null !== $owner) {
            $qb->andWhere('c.customerId = :owner')->setParameter('owner', $owner, CustomerIdType::NAME);
        }

        if (null !== $status) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status, CartStatusType::NAME);
        }

        return $qb;
    }

    public function expiredPendingIds(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<array{id: CartId}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(Cart::class, 'c')
            ->where('c.status = :pending')
            ->andWhere('c.expiresAt IS NOT NULL')
            ->andWhere('c.expiresAt <= :now')
            ->orderBy('c.expiresAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setParameter('pending', CartStatus::pending(), CartStatusType::NAME)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $row): CartId => $row['id'], $rows);
    }

    /**
     * Removes the line from the cart it belongs to; a line of another cart is
     * left alone. The handler has already checked ownership under a row lock,
     * so the `cart` condition here is the last line of defence, not the first.
     */
    public function removeItem(CartId $cartId, ItemId $itemId): void
    {
        $item = $this->em->createQueryBuilder()
            ->select('i')
            ->from(CartItem::class, 'i')
            ->where('i.id = :id')
            ->andWhere('i.cart = :cart')
            ->setParameter('id', $itemId, ItemIdType::NAME)
            ->setParameter('cart', $cartId, CartIdType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$item instanceof CartItem) {
            return;
        }

        $item->getCart()->removeItem($item);

        $this->em->remove($item);
        $this->em->flush();
    }
}
