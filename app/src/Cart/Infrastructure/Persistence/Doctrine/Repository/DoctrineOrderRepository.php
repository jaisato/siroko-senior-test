<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Repository\OrderRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\OrderId;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\CartIdType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\OrderIdType;

final class DoctrineOrderRepository implements OrderRepository
{
    use LocksRows;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function nextIdentity(): OrderId
    {
        return OrderId::fromString(Uuid::uuid7()->toString());
    }

    public function save(Order $order): void
    {
        $this->em->persist($order);
        $this->em->flush();
    }

    public function ofId(OrderId $id): ?Order
    {
        return $this->byId($id, null);
    }

    public function ofIdForUpdate(OrderId $id): ?Order
    {
        return $this->byId($id, LockMode::PESSIMISTIC_WRITE);
    }

    public function ofCart(CartId $cartId): ?Order
    {
        return $this->byCart($cartId, null);
    }

    public function ofCartForUpdate(CartId $cartId): ?Order
    {
        return $this->byCart($cartId, LockMode::PESSIMISTIC_WRITE);
    }

    /** @param LockMode::*|null $lock */
    private function byId(OrderId $id, ?int $lock): ?Order
    {
        $query = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.id = :id')
            ->setParameter('id', $id, OrderIdType::NAME)
            ->getQuery();

        $order = $this->locking($query, $lock)->getOneOrNullResult();

        return $order instanceof Order ? $order : null;
    }

    /** @param LockMode::*|null $lock */
    private function byCart(CartId $cartId, ?int $lock): ?Order
    {
        $query = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.cartId = :cartId')
            ->setParameter('cartId', $cartId, CartIdType::NAME)
            ->getQuery();

        $order = $this->locking($query, $lock)->getOneOrNullResult();

        return $order instanceof Order ? $order : null;
    }

    /**
     * @param Query<null, mixed> $query
     * @param LockMode::*|null   $lock
     *
     * @return Query<null, mixed>
     */
    private function locking(Query $query, ?int $lock): Query
    {
        return null === $lock ? $query : $this->forUpdate($query);
    }
}
