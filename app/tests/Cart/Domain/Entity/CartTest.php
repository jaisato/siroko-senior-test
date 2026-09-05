<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\Entity;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
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
    public function test_a_new_cart_is_pending_and_empty(): void
    {
        $cart = self::cart();

        self::assertTrue($cart->isPending());
        self::assertSame(CartStatus::PENDING, $cart->status()->toInt());
        self::assertCount(0, $cart->items());
    }

    public function test_add_item_links_both_sides_of_the_association(): void
    {
        $cart = self::cart();
        $item = self::item();

        $cart->addItem($item);

        self::assertCount(1, $cart->items());
        self::assertSame($cart, $item->getCart());
        self::assertTrue($item->belongsTo($cart));
    }

    public function test_add_item_is_idempotent_for_the_same_instance(): void
    {
        $cart = self::cart();
        $item = self::item();

        $cart->addItem($item);
        $cart->addItem($item);

        self::assertCount(1, $cart->items());
    }

    /**
     * The association is mapped with orphan-removal, so dropping the item from
     * the collection is what deletes it. removeItem() used to re-assign the
     * owning side to the very same cart, which did nothing at all.
     */
    public function test_remove_item_drops_it_from_the_collection(): void
    {
        $cart = self::cart();
        $first = self::item();
        $second = self::item();

        $cart->addItem($first);
        $cart->addItem($second);
        $cart->removeItem($first);

        self::assertCount(1, $cart->items());
        self::assertSame($second, $cart->items()->first());
    }

    public function test_removing_an_item_that_is_not_in_the_cart_leaves_it_untouched(): void
    {
        $cart = self::cart();
        $cart->addItem(self::item());

        $cart->removeItem(self::item());

        self::assertCount(1, $cart->items());
    }

    public function test_paying_a_pending_cart_makes_it_paid(): void
    {
        $cart = self::cart();

        $cart->pay();

        self::assertFalse($cart->isPending());
        self::assertSame(CartStatus::PAID, $cart->status()->toInt());
    }

    public function test_paying_twice_is_refused(): void
    {
        $cart = self::cart();
        $cart->pay();

        $this->expectException(InvalidCartStatusException::class);

        $cart->pay();
    }

    /**
     * Adding to a paid cart reserved stock that nothing would ever release,
     * since the removal path rightly refuses to return units that were sold.
     */
    public function test_a_paid_cart_accepts_no_new_items(): void
    {
        $cart = self::cart();
        $cart->pay();

        $this->expectException(InvalidCartStatusException::class);

        $cart->addItem(self::item());
    }

    public function test_a_paid_cart_keeps_its_items(): void
    {
        $cart = self::cart();
        $item = self::item();
        $cart->addItem($item);
        $cart->pay();

        try {
            $cart->removeItem($item);
            self::fail('a paid cart is immutable');
        } catch (InvalidCartStatusException) {
        }

        self::assertCount(1, $cart->items());
    }

    public function test_ensure_pending_names_the_conflict(): void
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::paid());

        $this->expectException(InvalidCartStatusException::class);
        $this->expectExceptionMessage('not pending');

        $cart->ensurePending();
    }

    private static function cart(): Cart
    {
        return new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());
    }

    private static function item(): CartItem
    {
        $product = new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('SKU-' . random_int(1000, 9999)),
            Name::fromString('A product'),
            Price::of(10, 'EUR'),
            new Quantity(1),
        );

        return new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
    }
}
