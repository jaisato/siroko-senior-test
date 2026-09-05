<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\Entity;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
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

final class CartItemTest extends TestCase
{
    public function test_an_item_knows_its_product_and_cart(): void
    {
        $id = ItemId::fromString(Uuid::uuid4()->toString());
        $product = self::product();
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());

        $item = new CartItem($id, $product);
        $item->setCart($cart);

        self::assertSame($id, $item->id());
        self::assertSame($product, $item->getProduct());
        self::assertSame($cart, $item->getCart());
    }

    public function test_belongs_to_compares_cart_identities(): void
    {
        $cartId = Uuid::uuid4()->toString();
        $cart = new Cart(CartId::fromString($cartId), CartStatus::pending());
        $sameIdentity = new Cart(CartId::fromString($cartId), CartStatus::paid());
        $other = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());

        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product());

        self::assertFalse($item->belongsTo($cart), 'an item that was never added belongs to nobody');

        $item->setCart($cart);

        self::assertTrue($item->belongsTo($cart));
        self::assertTrue($item->belongsTo($sameIdentity));
        self::assertFalse($item->belongsTo($other));
    }

    public function test_the_product_can_be_replaced(): void
    {
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product());
        $other = self::product();

        $item->setProduct($other);

        self::assertSame($other, $item->getProduct());
    }

    private static function product(): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('SKU'),
            Name::fromString('A product'),
            Price::of('1.00', 'EUR'),
        );
    }
}
