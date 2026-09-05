<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\ChangeCartItemQuantityCommand;
use Siroko\Cart\Application\Command\Cart\ChangeCartItemQuantityCommandHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\CartItemNotFoundException;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Exception\OutOfStockException;
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
 * Changing the quantity of a line is a stock movement of the difference, and
 * it has to be settled inside the same transaction and under the same locks as
 * the other cart writes, or two requests on the same line each settle the
 * wrong number of units.
 */
final class ChangeCartItemQuantityCommandHandlerTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: int}> stock movements as (kind, productId, units) */
    private array $movements = [];

    /** @var list<string> rows locked, in the order the handler asked for them */
    private array $locked = [];

    private RecordingSession $session;

    private bool $stockAvailable = true;

    protected function setUp(): void
    {
        $this->movements = [];
        $this->locked = [];
        $this->stockAvailable = true;
        $this->session = new RecordingSession();
    }

    public function test_growing_a_line_reserves_the_extra_units_only(): void
    {
        $product = $this->product();
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product, 2);

        $read = $this->handler($cart, $item)(new ChangeCartItemQuantityCommand($cart->id()->toString(), $item->id()->toString(), 5));

        self::assertSame(5, $item->quantity()->asInt());
        self::assertSame([['reserve', $product->id()->toString(), 3]], $this->movements);
        self::assertSame(5, $read->items[0]->quantity);
    }

    public function test_shrinking_a_line_returns_the_surplus_units_only(): void
    {
        $product = $this->product();
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product, 5);

        $this->handler($cart, $item)(new ChangeCartItemQuantityCommand($cart->id()->toString(), $item->id()->toString(), 2));

        self::assertSame(2, $item->quantity()->asInt());
        self::assertSame([['return', $product->id()->toString(), 3]], $this->movements);
    }

    public function test_the_same_quantity_moves_no_stock(): void
    {
        $product = $this->product();
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product, 2);

        $this->handler($cart, $item)(new ChangeCartItemQuantityCommand($cart->id()->toString(), $item->id()->toString(), 2));

        self::assertSame([], $this->movements);
        self::assertContains('saveCart', $this->session->log);
    }

    /** Zero is a removal: the line goes and every unit it held comes back. */
    public function test_zero_removes_the_line_and_returns_all_its_units(): void
    {
        $product = $this->product();
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product, 4);

        $this->handler($cart, $item)(new ChangeCartItemQuantityCommand($cart->id()->toString(), $item->id()->toString(), 0));

        self::assertSame([['return', $product->id()->toString(), 4]], $this->movements);
        self::assertContains('removeItem', $this->session->log);
    }

    public function test_growing_beyond_the_available_stock_is_refused_and_the_line_is_untouched_in_the_database(): void
    {
        $product = $this->product();
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product, 2);
        $this->stockAvailable = false;

        try {
            $this->handler($cart, $item)(new ChangeCartItemQuantityCommand($cart->id()->toString(), $item->id()->toString(), 9));
            self::fail('expected an exception');
        } catch (OutOfStockException) {
        }

        self::assertNotContains('saveCart', $this->session->log, 'the transaction is abandoned before any write');
    }

    /**
     * Cart, then line, then product - the order every cart write uses. The
     * product lock is the stock UPDATE itself, so it shows up as the movement.
     */
    public function test_locks_are_taken_cart_first_then_line_then_product(): void
    {
        $product = $this->product();
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product, 1);

        $this->handler($cart, $item)(new ChangeCartItemQuantityCommand($cart->id()->toString(), $item->id()->toString(), 3));

        self::assertSame(['cart:' . $cart->id()->toString(), 'item:' . $item->id()->toString()], $this->locked);
        self::assertSame(1, $this->session->transactions);
        self::assertSame(['begin', 'lockCart', 'lockItem', 'reserve', 'saveCart', 'commit'], $this->session->log);
    }

    public function test_a_cart_that_is_not_pending_is_refused_before_the_line_is_loaded(): void
    {
        $product = $this->product();
        $cart = $this->cart(CartStatus::PAID);
        $item = $this->itemIn($cart, $product, 1);

        try {
            $this->handler($cart, $item)(new ChangeCartItemQuantityCommand($cart->id()->toString(), $item->id()->toString(), 3));
            self::fail('expected an exception');
        } catch (InvalidCartStatusException) {
        }

        self::assertSame([], $this->movements);
        self::assertSame(['cart:' . $cart->id()->toString()], $this->locked);
    }

    public function test_an_unknown_cart_is_not_found(): void
    {
        $this->expectException(CartNotFoundException::class);

        $this->handler(null, null)(new ChangeCartItemQuantityCommand(Uuid::uuid4()->toString(), Uuid::uuid4()->toString(), 3));
    }

    public function test_an_unknown_line_is_not_found(): void
    {
        $cart = $this->cart(CartStatus::PENDING);

        try {
            $this->handler($cart, null)(new ChangeCartItemQuantityCommand($cart->id()->toString(), Uuid::uuid4()->toString(), 3));
            self::fail('expected an exception');
        } catch (CartItemNotFoundException) {
        }

        self::assertSame([], $this->movements);
    }

    /** From this cart's point of view a line of another cart does not exist, and its stock is not ours to move. */
    public function test_a_line_of_another_cart_is_not_found_and_moves_nothing(): void
    {
        $product = $this->product();
        $owner = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($owner, $product, 2);
        $other = $this->cart(CartStatus::PENDING);

        try {
            $this->handler($other, $item)(new ChangeCartItemQuantityCommand($other->id()->toString(), $item->id()->toString(), 5));
            self::fail('expected an exception');
        } catch (CartItemNotFoundException) {
        }

        self::assertSame([], $this->movements);
        self::assertSame(2, $item->quantity()->asInt());
    }

    #[DataProvider('quantitiesThatAreNotALine')]
    public function test_the_command_refuses_quantities_a_line_cannot_hold(int|string $quantity): void
    {
        $this->expectException(InvalidQuantityException::class);

        new ChangeCartItemQuantityCommand(Uuid::uuid4()->toString(), Uuid::uuid4()->toString(), $quantity);
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function quantitiesThatAreNotALine(): iterable
    {
        yield 'negative' => [-1];
        yield 'over the cap' => [CartItem::MAX_QUANTITY + 1];
        yield 'not a number' => ['many'];
    }

    public function test_the_command_accepts_zero_as_a_removal(): void
    {
        $command = new ChangeCartItemQuantityCommand(Uuid::uuid4()->toString(), Uuid::uuid4()->toString(), 0);

        self::assertTrue($command->removesTheLine());
        self::assertFalse((new ChangeCartItemQuantityCommand(Uuid::uuid4()->toString(), Uuid::uuid4()->toString(), '2'))->removesTheLine());
    }

    public function test_the_command_validates_its_identifiers(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new ChangeCartItemQuantityCommand(Uuid::uuid4()->toString(), 'line-1', 1);
    }

    private function cart(int $status): Cart
    {
        return new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus($status));
    }

    private function itemIn(Cart $cart, Product $product, int $units): CartItem
    {
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity($units));

        if ($cart->isPending()) {
            $cart->addItem($item);
        } else {
            $item->setCart($cart);
        }

        return $item;
    }

    private function product(): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('ABC123'),
            Name::fromString('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity(50),
        );
    }

    private function handler(?Cart $lockedCart, ?CartItem $lockedItem): ChangeCartItemQuantityCommandHandler
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('ofIdForUpdate')->willReturnCallback(function (CartId $id) use ($lockedCart): ?Cart {
            $this->locked[] = 'cart:' . $id->toString();
            $this->session->log[] = 'lockCart';

            return $lockedCart;
        });
        $carts->method('ofId')->willReturnCallback(static fn() => self::fail('the cart must be loaded with its row locked'));
        $carts->method('save')->willReturnCallback(function (): void {
            $this->session->log[] = 'saveCart';
        });
        $carts->method('removeItem')->willReturnCallback(function (): void {
            $this->session->log[] = 'removeItem';
        });

        $items = $this->createStub(CartItemRepository::class);
        $items->method('ofIdForUpdate')->willReturnCallback(function (ItemId $id) use ($lockedItem): ?CartItem {
            $this->locked[] = 'item:' . $id->toString();
            $this->session->log[] = 'lockItem';

            return $lockedItem;
        });
        $items->method('ofId')->willReturnCallback(static fn() => self::fail('the line must be loaded with its row locked'));

        $products = $this->createStub(ProductRepository::class);
        $products->method('reserveStock')->willReturnCallback(function (ProductId $id, int $units): bool {
            $this->session->log[] = 'reserve';
            if (!$this->stockAvailable) {
                return false;
            }
            $this->movements[] = ['reserve', $id->toString(), $units];

            return true;
        });
        $products->method('returnStock')->willReturnCallback(function (ProductId $id, int $units): void {
            $this->session->log[] = 'return';
            $this->movements[] = ['return', $id->toString(), $units];
        });

        return new ChangeCartItemQuantityCommandHandler($carts, $items, $products, $this->session);
    }
}
