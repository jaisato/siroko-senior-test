<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\Entity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

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

    /** A line stands for one unit unless told otherwise, as it always did. */
    public function test_a_line_holds_one_unit_by_default(): void
    {
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product());

        self::assertSame(1, $item->quantity()->asInt());
        self::assertSame(4, (new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(4)))->quantity()->asInt());
    }

    public function test_increase_adds_units_to_the_line(): void
    {
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(2));

        $item->increase(new Quantity(3));

        self::assertSame(5, $item->quantity()->asInt());
    }

    /** The difference is what the caller has to settle with the stock. */
    public function test_change_quantity_reports_the_units_to_reserve_or_return(): void
    {
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(2));

        self::assertSame(3, $item->changeQuantity(new Quantity(5)), 'three more to reserve');
        self::assertSame(5, $item->quantity()->asInt());
        self::assertSame(-4, $item->changeQuantity(new Quantity(1)), 'four to give back');
        self::assertSame(0, $item->changeQuantity(new Quantity(1)));
    }

    #[DataProvider('quantitiesALineRefuses')]
    public function test_a_line_refuses_quantities_outside_its_bounds(int $units): void
    {
        $this->expectException(InvalidQuantityException::class);

        new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity($units));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function quantitiesALineRefuses(): iterable
    {
        yield 'zero' => [0];
        yield 'over the cap' => [CartItem::MAX_QUANTITY + 1];
    }

    public function test_a_line_cannot_grow_past_the_cap(): void
    {
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(CartItem::MAX_QUANTITY));

        $this->expectException(InvalidQuantityException::class);

        $item->increase(new Quantity(1));
    }

    public function test_a_line_cannot_be_set_to_zero_through_change_quantity(): void
    {
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(2));

        try {
            $item->changeQuantity(new Quantity(0));
            self::fail('zero is a removal, not a quantity');
        } catch (InvalidQuantityException) {
        }

        self::assertSame(2, $item->quantity()->asInt(), 'a refused change leaves the line as it was');
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
