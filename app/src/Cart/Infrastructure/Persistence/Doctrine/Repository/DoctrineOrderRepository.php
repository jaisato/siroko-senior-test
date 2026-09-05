<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Repository\OrderRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\OrderId;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\CartIdType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\OrderIdType;

final class DoctrineOrderRepository implements OrderRepository
{
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
        $order = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.id = :id')
            ->setParameter('id', $id, OrderIdType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $order instanceof Order ? $order : null;
    }

    public function ofCart(CartId $cartId): ?Order
    {
        $order = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.cartId = :cartId')
            ->setParameter('cartId', $cartId, CartIdType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $order instanceof Order ? $order : null;
    }
}
