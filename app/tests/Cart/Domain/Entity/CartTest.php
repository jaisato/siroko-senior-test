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

    /**
     * A cart holds one line per product. Adding a product it already holds
     * grows that line, so "add" means "one more unit", not "one more row".
     */
    public function test_add_product_grows_the_existing_line_of_the_product(): void
    {
        $cart = self::cart();
        $product = self::product();

        $first = $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(2));
        $second = $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(3));

        self::assertSame($first, $second, 'the same line is returned');
        self::assertCount(1, $cart->items());
        self::assertSame(5, $first->quantity()->asInt());
        self::assertSame($cart, $first->getCart());
    }

    public function test_add_product_opens_a_line_per_distinct_product(): void
    {
        $cart = self::cart();

        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(1));
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(1));

        self::assertCount(2, $cart->items());
    }

    public function test_add_product_is_refused_on_a_paid_cart(): void
    {
        $cart = self::cart();
        $cart->pay();

        $this->expectException(InvalidCartStatusException::class);

        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(1));
    }

    /** A ready-made line for a product the cart already holds folds into the existing one. */
    public function test_add_item_folds_a_second_line_of_the_same_product_into_the_first(): void
    {
        $cart = self::cart();
        $product = self::product();
        $first = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(2));
        $second = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(3));

        $cart->addItem($first);
        $cart->addItem($second);

        self::assertCount(1, $cart->items());
        self::assertSame(5, $first->quantity()->asInt());
        self::assertFalse($second->belongsTo($cart), 'the folded line never joined the cart');
    }

    public function test_a_line_is_found_by_its_id_or_by_its_product(): void
    {
        $cart = self::cart();
        $product = self::product();
        $line = $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity(1));

        self::assertSame($line, $cart->itemOfId($line->id()));
        self::assertSame($line, $cart->itemForProduct($product->id()));
        self::assertNull($cart->itemOfId(ItemId::fromString(Uuid::uuid4()->toString())));
        self::assertNull($cart->itemForProduct(ProductId::fromString(Uuid::uuid4()->toString())));
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
        return new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product());
    }

    private static function product(): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('SKU-' . random_int(1000, 9999)),
            Name::fromString('A product'),
            Price::of(10, 'EUR'),
            new Quantity(1),
        );
    }
}
