<?php

namespace Siroko\Tests\Cart\Domain\Entity;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * Pure domain tests - no database, no container.
 */
final class CartTest extends TestCase
{
    public function testAddItemLinksBothSidesOfTheAssociation(): void
    {
        $cart = self::cart();
        $item = self::item('item-1');

        $cart->addItem($item);

        self::assertCount(1, $cart->items());
        self::assertSame($cart, $item->getCart());
    }

    public function testAddItemIsIdempotentForTheSameInstance(): void
    {
        $cart = self::cart();
        $item = self::item('item-1');

        $cart->addItem($item);
        $cart->addItem($item);

        self::assertCount(1, $cart->items());
    }

    /**
     * The association is mapped with orphan-removal, so dropping the item from
     * the collection is what deletes it. removeItem() used to re-assign the
     * owning side to the very same cart, which did nothing at all.
     */
    public function testRemoveItemDropsItFromTheCollection(): void
    {
        $cart = self::cart();
        $first = self::item('item-1');
        $second = self::item('item-2');

        $cart->addItem($first);
        $cart->addItem($second);
        $cart->removeItem($first);

        self::assertCount(1, $cart->items());
        self::assertSame($second, $cart->items()->first());
    }

    public function testRemovingAnItemThatIsNotInTheCartLeavesItUntouched(): void
    {
        $cart = self::cart();
        $cart->addItem(self::item('item-1'));

        $cart->removeItem(self::item('item-2'));

        self::assertCount(1, $cart->items());
    }

    private static function cart(): Cart
    {
        return new Cart(CartId::fromString('cart-1'), new CartStatus(CartStatus::PENDING));
    }

    private static function item(string $id): CartItem
    {
        $product = new Product(
            ProductId::fromString('product-'.$id),
            new ProductCode('SKU-'.$id),
            new Name('Product '.$id),
            Price::of(10, 'EUR'),
            new Quantity(1),
        );

        return new CartItem(ItemId::fromString($id), $product);
    }
}
