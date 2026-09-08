<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\OrderRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\OrderId;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The order mapping: the JSON snapshot of the lines, the embedded total, the
 * timestamps, and one order per cart. Runs on SQLite and MySQL alike.
 */
final class DoctrineOrderRepositoryTest extends KernelTestCase
{
    private OrderRepository $repository;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = static::getContainer()->get(OrderRepository::class);
        $this->repository = $repository;

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;
    }

    public function test_an_order_round_trips_with_its_lines_total_and_timestamps(): void
    {
        $cart = $this->paidCart();
        $order = Order::place($this->repository->nextIdentity(), $cart, new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC')));
        $order->confirm(new \DateTimeImmutable('2026-09-06 10:05:00', new \DateTimeZone('UTC')));
        $this->repository->save($order);

        $this->em->clear();
        $reloaded = $this->repository->ofId($order->id());

        self::assertInstanceOf(Order::class, $reloaded);
        self::assertNotSame($order, $reloaded);
        self::assertTrue($order->id()->equals($reloaded->id()));
        self::assertTrue($cart->id()->equals($reloaded->cartId()));
        self::assertSame(3, $reloaded->itemCount());
        self::assertTrue(Price::of('269.89', 'EUR')->equals($reloaded->total()));
        self::assertSame('2026-09-06 10:00:00', $reloaded->createdAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-06 10:05:00', $reloaded->confirmedAt()?->format('Y-m-d H:i:s'));

        $lines = $reloaded->lines();
        self::assertCount(2, $lines);
        self::assertSame(array_map(static fn($line) => $line->toArray(), $order->lines()), array_map(static fn($line) => $line->toArray(), $lines));
    }

    public function test_an_order_is_found_by_its_cart(): void
    {
        $cart = $this->paidCart();
        $order = Order::place($this->repository->nextIdentity(), $cart, new \DateTimeImmutable());
        $this->repository->save($order);

        $this->em->clear();

        self::assertTrue($order->id()->equals($this->repository->ofCart($cart->id())?->id() ?? OrderId::fromString(Uuid::uuid4()->toString())));
        self::assertNull($this->repository->ofCart(CartId::fromString(Uuid::uuid4()->toString())));
    }

    /** A cart is paid once: the database holds it to one order. */
    public function test_the_database_refuses_a_second_order_for_the_same_cart(): void
    {
        $cart = $this->paidCart();
        $this->repository->save(Order::place($this->repository->nextIdentity(), $cart, new \DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository->save(Order::place($this->repository->nextIdentity(), $cart, new \DateTimeImmutable()));
    }

    public function test_an_unknown_id_is_null(): void
    {
        self::assertNull($this->repository->ofId($this->repository->nextIdentity()));
    }

    private function paidCart(): Cart
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());

        foreach ([['Gafas', '129.95', 2], ['Funda', '9.99', 1]] as [$name, $amount, $units]) {
            $product = new Product(
                ProductId::fromString(Uuid::uuid4()->toString()),
                ProductCode::fromString(strtoupper(substr(Uuid::uuid4()->toString(), 0, 8))),
                Name::fromString($name),
                Price::of($amount, 'EUR'),
                new Quantity(10),
            );
            $this->em->persist($product);
            $cart->addItem(new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity($units)));
        }

        $cart->pay();
        $this->em->persist($cart);
        $this->em->flush();

        return $cart;
    }
}
