<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\CartIdType;

class DoctrineCartRepository implements CartRepository
{
    /**
     * @param EntityManagerInterface $em
     */
    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    /**
     * @return CartId
     */
    public function nextIdentity(): CartId
    {
        return CartId::fromString(Uuid::uuid7()->toString());
    }

    /**
     * @param Cart $cart
     * @return void
     */
    public function save(Cart $cart): void
    {
        $this->em->persist($cart);
        $this->em->flush();
    }

    /**
     * @param CartId $id
     * @return Cart|null
     */
    public function ofId(CartId $id): ?Cart
    {
        // return $this->em->find(Product::class, $id);

        $qb = $this->em->createQueryBuilder();

        $qb->select('c')
            ->from(Cart::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id, CartIdType::NAME);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
