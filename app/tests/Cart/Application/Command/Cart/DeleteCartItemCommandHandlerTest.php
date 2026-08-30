<?php

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\DeleteCartItemCommand;
use Siroko\Cart\Application\Command\Cart\DeleteCartItemCommandHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * Adding a product reserves a unit off its stock. Removing the item has to give
 * that unit back, or every add/remove cycle destroys one unit of inventory and
 * the product eventually reports itself out of stock with nothing sold.
 */
final class DeleteCartItemCommandHandlerTest extends TestCase
{
    public function test_removing_an_item_returns_its_reserved_unit_to_the_product(): void
    {
        $product = $this->product(quantity: 4);
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus(CartStatus::PENDING));
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
        $cart->addItem($item);

        $handler = new DeleteCartItemCommandHandler(
            $this->expectsRemoval(),
            $this->itemRepositoryReturning($item),
            $this->expectsSave(),
        );

        $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));

        self::assertSame(5, $product->quantity()->asInt());
    }

    /**
     * A delete aimed at a cart that does not own the item must not credit any
     * stock - otherwise a repeated or mistargeted request mints inventory,
     * which is the same bug pointing the other way.
     */
    public function test_an_item_belonging_to_another_cart_does_not_credit_stock(): void
    {
        $product = $this->product(quantity: 4);
        $otherCart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus(CartStatus::PENDING));
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
        $otherCart->addItem($item);

        $handler = new DeleteCartItemCommandHandler(
            $this->createStub(CartRepository::class),
            $this->itemRepositoryReturning($item),
            $this->expectsNoSave(),
        );

        $handler(new DeleteCartItemCommand(Uuid::uuid4()->toString(), $item->id()->toString()));

        self::assertSame(4, $product->quantity()->asInt());
    }

    /** Once a cart is paid the unit is sold, not reserved, so it is not ours to return. */
    public function test_a_paid_cart_does_not_credit_stock(): void
    {
        $product = $this->product(quantity: 4);
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus(CartStatus::PAID));
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
        $cart->addItem($item);

        $handler = new DeleteCartItemCommandHandler(
            $this->createStub(CartRepository::class),
            $this->itemRepositoryReturning($item),
            $this->expectsNoSave(),
        );

        $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));

        self::assertSame(4, $product->quantity()->asInt());
    }

    public function test_an_unknown_item_is_a_no_op_for_stock(): void
    {
        $items = $this->createStub(CartItemRepository::class);
        $items->method('ofId')->willReturn(null);

        $handler = new DeleteCartItemCommandHandler(
            $this->createStub(CartRepository::class),
            $items,
            $this->expectsNoSave(),
        );

        $handler(new DeleteCartItemCommand(Uuid::uuid4()->toString(), Uuid::uuid4()->toString()));

        $this->addToAssertionCount(1);
    }

    private function product(int $quantity): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            new ProductCode('ABC123'),
            new Name('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity($quantity),
        );
    }

    private function itemRepositoryReturning(CartItem $item): CartItemRepository
    {
        $items = $this->createStub(CartItemRepository::class);
        $items->method('ofId')->willReturn($item);

        return $items;
    }

    private function expectsRemoval(): CartRepository
    {
        $carts = $this->createMock(CartRepository::class);
        $carts->expects(self::once())->method('removeItem');

        return $carts;
    }

    private function expectsSave(): ProductRepository
    {
        $products = $this->createMock(ProductRepository::class);
        $products->expects(self::once())->method('save');

        return $products;
    }

    private function expectsNoSave(): ProductRepository
    {
        $products = $this->createMock(ProductRepository::class);
        $products->expects(self::never())->method('save');

        return $products;
    }
}
