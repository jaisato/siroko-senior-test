<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The cart mapping: a line carries its quantity, and the database holds the
 * aggregate to one line per product. Runs on SQLite and MySQL alike.
 */
final class DoctrineCartRepositoryTest extends KernelTestCase
{
    private CartRepository $repository;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = static::getContainer()->get(CartRepository::class);
        $this->repository = $repository;

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;
    }

    public function test_a_cart_round_trips_with_the_quantity_of_each_line(): void
    {
        $product = $this->product();
        $cart = new Cart($this->repository->nextIdentity(), CartStatus::pending());
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(3));
        $this->repository->save($cart);

        $this->em->clear();
        $reloaded = $this->repository->ofId($cart->id());

        self::assertInstanceOf(Cart::class, $reloaded);
        self::assertCount(1, $reloaded->items());
        $line = $reloaded->items()->first();
        self::assertInstanceOf(CartItem::class, $line);
        self::assertSame(3, $line->quantity()->asInt());
        self::assertTrue($product->id()->equals($line->getProduct()->id()));
    }

    /**
     * The aggregate merges lines of one product; the unique index is what
     * makes that hold when two requests race past the merge.
     */
    public function test_the_database_refuses_a_second_line_for_the_same_product_in_a_cart(): void
    {
        $product = $this->product();
        $cart = new Cart($this->repository->nextIdentity(), CartStatus::pending());
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(1));
        $this->repository->save($cart);

        // Bypasses the aggregate on purpose: a line persisted on its own.
        $twin = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(1));
        $twin->setCart($cart);
        $this->em->persist($twin);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    public function test_the_same_product_may_be_in_two_different_carts(): void
    {
        $product = $this->product();

        foreach ([1, 2] as $_) {
            $cart = new Cart($this->repository->nextIdentity(), CartStatus::pending());
            $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(1));
            $this->repository->save($cart);
        }

        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(CartItem::class)->findAll());
    }

    public function test_an_unknown_cart_is_null(): void
    {
        self::assertNull($this->repository->ofId($this->repository->nextIdentity()));
    }

    private function product(): Product
    {
        $product = new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString(strtoupper(substr(Uuid::uuid4()->toString(), 0, 8))),
            Name::fromString('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity(50),
        );

        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
