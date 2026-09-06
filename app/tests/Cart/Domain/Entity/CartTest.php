<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\Entity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\CartIsFullException;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\EmptyCartException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\PriceIsNotSameCurrencyException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\CustomerId;
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
        $cart->addItem(self::item());

        $cart->pay();

        self::assertFalse($cart->isPending());
        self::assertSame(CartStatus::PAID, $cart->status()->toInt());
    }

    public function test_paying_twice_is_refused(): void
    {
        $cart = self::cart();
        $cart->addItem(self::item());
        $cart->pay();

        $this->expectException(InvalidCartStatusException::class);

        $cart->pay();
    }

    /** A payment for nothing is not a payment; checkout used to accept it. */
    public function test_an_empty_cart_cannot_be_paid(): void
    {
        $cart = self::cart();

        try {
            $cart->pay();
            self::fail('expected an exception');
        } catch (EmptyCartException $e) {
            self::assertStringContainsString('empty', $e->getMessage());
        }

        self::assertTrue($cart->isPending(), 'the refusal leaves the cart as it was');
    }

    public function test_a_paid_cart_can_be_delivered_and_then_nothing_else(): void
    {
        $cart = self::cart();
        $cart->addItem(self::item());
        $cart->pay();

        $cart->deliver();

        self::assertSame(CartStatus::DELIVERED, $cart->status()->toInt());

        foreach (['deliver', 'cancel', 'pay'] as $transition) {
            try {
                $cart->{$transition}();
                self::fail(\sprintf('%s() must be refused on a delivered cart', $transition));
            } catch (InvalidCartStatusException) {
            }
        }

        self::assertSame(CartStatus::DELIVERED, $cart->status()->toInt());
    }

    public function test_only_a_paid_cart_can_be_delivered(): void
    {
        $cart = self::cart();

        $this->expectException(InvalidCartStatusException::class);
        $this->expectExceptionMessage('not paid');

        $cart->deliver();
    }

    #[DataProvider('cancellableStatuses')]
    public function test_a_pending_or_paid_cart_can_be_canceled(int $status): void
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus($status));

        $cart->cancel();

        self::assertSame(CartStatus::CANCELED, $cart->status()->toInt());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function cancellableStatuses(): iterable
    {
        yield 'pending' => [CartStatus::PENDING];
        yield 'paid' => [CartStatus::PAID];
    }

    public function test_a_canceled_cart_is_final(): void
    {
        $cart = self::cart();
        $cart->addItem(self::item());
        $cart->cancel();

        foreach (['cancel', 'deliver', 'pay'] as $transition) {
            try {
                $cart->{$transition}();
                self::fail(\sprintf('%s() must be refused on a canceled cart', $transition));
            } catch (InvalidCartStatusException) {
            }
        }

        self::assertCount(1, $cart->items(), 'the lines stay as a record of what was in it');
    }

    /**
     * Adding to a paid cart reserved stock that nothing would ever release,
     * since the removal path rightly refuses to return units that were sold.
     */
    public function test_a_paid_cart_accepts_no_new_items(): void
    {
        $cart = self::cart();
        $cart->addItem(self::item());
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
        $cart->addItem(self::item());
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

    public function test_an_empty_cart_has_no_currency_and_no_total(): void
    {
        $cart = self::cart();

        self::assertNull($cart->currency());
        self::assertNull($cart->subtotal());
        self::assertNull($cart->total());
        self::assertSame(0, $cart->itemCount());
    }

    public function test_the_cart_adds_up_its_lines(): void
    {
        $cart = self::cart();
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product('129.95'), new Quantity(2));
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product('9.99'), new Quantity(1));

        self::assertSame('EUR', $cart->currency()?->getCurrencyCode());
        self::assertSame(3, $cart->itemCount());
        self::assertTrue(Price::of('269.89', 'EUR')->equals($cart->subtotal() ?? Price::zero('EUR')));
        self::assertTrue(Price::of('269.89', 'EUR')->equals($cart->total() ?? Price::zero('EUR')), 'no taxes or discounts yet');
    }

    /** Lines in two currencies have no total, so the mix is refused when the line arrives. */
    public function test_a_product_in_another_currency_is_refused(): void
    {
        $cart = self::cart();
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product('10.00', 'EUR'), new Quantity(1));

        try {
            $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product('10.00', 'USD'), new Quantity(1));
            self::fail('expected an exception');
        } catch (PriceIsNotSameCurrencyException $e) {
            self::assertStringContainsString('EUR', $e->getMessage());
            self::assertStringContainsString('USD', $e->getMessage());
        }

        self::assertCount(1, $cart->items());

        $this->expectException(PriceIsNotSameCurrencyException::class);

        $cart->addItem(new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product('10.00', 'USD')));
    }

    public function test_the_first_line_sets_the_currency(): void
    {
        $cart = self::cart();

        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product('10.00', 'USD'), new Quantity(1));
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product('5.00', 'USD'), new Quantity(1));

        self::assertSame('USD', $cart->currency()?->getCurrencyCode());
        self::assertSame('15.00', $cart->subtotal()?->amount());
    }

    public function test_a_cart_opened_now_expires_after_the_reservation_ttl(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));

        $cart = Cart::open(CartId::fromString(Uuid::uuid4()->toString()), $now, new \DateInterval('PT30M'));

        self::assertTrue($cart->isPending());
        self::assertSame($now, $cart->createdAt());
        self::assertSame('2026-09-06 10:30:00', $cart->expiresAt()?->format('Y-m-d H:i:s'));
        self::assertFalse($cart->isExpiredAt($now));
        self::assertFalse($cart->isExpiredAt($now->modify('+29 minutes 59 seconds')));
        self::assertTrue($cart->isExpiredAt($now->modify('+30 minutes')), 'the deadline itself counts as lapsed');
        self::assertTrue($cart->isExpiredAt($now->modify('+1 day')));
    }

    /** Paid units are sold, not reserved: nothing is left to expire. */
    public function test_paying_or_canceling_clears_the_deadline(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $paid = Cart::open(CartId::fromString(Uuid::uuid4()->toString()), $now, new \DateInterval('PT30M'));
        $paid->addItem(self::item());
        $canceled = Cart::open(CartId::fromString(Uuid::uuid4()->toString()), $now, new \DateInterval('PT30M'));

        $paid->pay();
        $canceled->cancel();

        self::assertNull($paid->expiresAt());
        self::assertNull($canceled->expiresAt());
        self::assertFalse($paid->isExpiredAt($now->modify('+1 day')), 'a paid cart never expires');
        self::assertFalse($canceled->isExpiredAt($now->modify('+1 day')));
    }

    public function test_a_cart_built_directly_is_dated_now_and_has_no_deadline(): void
    {
        $cart = self::cart();

        self::assertEqualsWithDelta(time(), $cart->createdAt()->getTimestamp(), 5);
        self::assertNull($cart->expiresAt());
        self::assertFalse($cart->isExpiredAt(new \DateTimeImmutable('+1 year')), 'no deadline, no expiry');
    }

    public function test_a_cart_may_be_opened_for_a_customer(): void
    {
        $alice = CustomerId::fromString('alice');
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));

        $owned = Cart::open(CartId::fromString(Uuid::uuid4()->toString()), $now, new \DateInterval('PT30M'), $alice);
        $ownerless = Cart::open(CartId::fromString(Uuid::uuid4()->toString()), $now, new \DateInterval('PT30M'));

        self::assertSame($alice, $owned->customerId());
        self::assertNull($ownerless->customerId());
        self::assertNull(self::cart()->customerId(), 'a cart built directly belongs to nobody');
    }

    /**
     * No caller (authentication off) sees everything; an owner's cart is the
     * owner's alone; an ownerless cart is open to any caller.
     */
    public function test_who_may_access_a_cart(): void
    {
        $alice = CustomerId::fromString('alice');
        $bob = CustomerId::fromString('bob');
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $alices = Cart::open(CartId::fromString(Uuid::uuid4()->toString()), $now, new \DateInterval('PT30M'), $alice);
        $ownerless = self::cart();

        self::assertTrue($alices->isAccessibleBy(null));
        self::assertTrue($alices->isAccessibleBy(CustomerId::fromString('alice')), 'compared by value');
        self::assertFalse($alices->isAccessibleBy($bob));
        self::assertTrue($ownerless->isAccessibleBy(null));
        self::assertTrue($ownerless->isAccessibleBy($bob));

        $alices->ensureAccessibleBy($alice);

        $this->expectException(CartNotFoundException::class);

        $alices->ensureAccessibleBy($bob);
    }

    public function test_ensure_pending_names_the_conflict(): void
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::paid());

        $this->expectException(InvalidCartStatusException::class);
        $this->expectExceptionMessage('not pending');

        $cart->ensurePending();
    }

    /**
     * The cap was checked only where a whole cart arrives at once, so a client
     * adding products one request at a time walked past it. It is not a round
     * number for the sake of it: Price::MAX_AMOUNT is computed from it, and a
     * cart with more lines can build a total wider than orders.total_amount
     * and fail at checkout with a 500, having been accepted all the way there.
     */
    public function test_a_cart_refuses_a_product_past_its_line_limit(): void
    {
        $cart = self::cart();

        for ($line = 0; $line < Cart::MAX_LINES; ++$line) {
            $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(1));
        }

        self::assertCount(Cart::MAX_LINES, $cart->items());

        $this->expectException(CartIsFullException::class);
        $this->expectExceptionMessage('at most ' . Cart::MAX_LINES . ' distinct products');

        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(1));
    }

    /** More units of a product the cart already holds is not another line. */
    public function test_a_full_cart_still_takes_more_of_what_it_already_holds(): void
    {
        $cart = self::cart();
        $first = self::product();
        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $first, new Quantity(1));

        for ($line = 1; $line < Cart::MAX_LINES; ++$line) {
            $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(1));
        }

        $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), $first, new Quantity(2));

        self::assertCount(Cart::MAX_LINES, $cart->items());
    }

    /** The ready-made line takes the same road and meets the same cap. */
    public function test_the_line_limit_holds_however_the_line_arrives(): void
    {
        $cart = self::cart();

        for ($line = 0; $line < Cart::MAX_LINES; ++$line) {
            $cart->addProduct(ItemId::fromString(Uuid::uuid4()->toString()), self::product(), new Quantity(1));
        }

        $this->expectException(CartIsFullException::class);

        $cart->addItem(self::item());
    }

    private static function cart(): Cart
    {
        return new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());
    }

    private static function item(): CartItem
    {
        return new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product());
    }

    private static function product(string $amount = '10.00', string $currency = 'EUR'): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('SKU-' . random_int(1000, 9999)),
            Name::fromString('A product'),
            Price::of($amount, $currency),
            new Quantity(1),
        );
    }
}
