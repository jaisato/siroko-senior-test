<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Product;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Product\AdjustProductStockCommand;
use Siroko\Cart\Application\Command\Product\AdjustProductStockCommandHandler;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Exception\InvalidStockAdjustmentException;
use Siroko\Cart\Domain\Exception\OutOfStockException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Siroko\Tests\Cart\Application\Command\Cart\RecordingSession;

/**
 * Stock adjustments go through the same atomic movements the cart uses, so
 * they compose with reservations happening at the same time instead of
 * overwriting them.
 */
final class AdjustProductStockCommandHandlerTest extends TestCase
{
    /** @var list<array{0: string, 1: int|string}> movements as (kind, units) */
    private array $movements = [];

    /** @var list<string> the reads that take locks, in the order they were made */
    private array $reads = [];

    private int $available = 5;

    protected function setUp(): void
    {
        $this->movements = [];
        $this->reads = [];
        $this->available = 5;
    }

    public function test_an_absolute_quantity_is_a_recount(): void
    {
        $product = $this->product();

        $read = $this->handler($product)(new AdjustProductStockCommand($product->id()->toString(), quantity: 40));

        self::assertSame([['set', 40]], $this->movements);
        self::assertSame(40, $read->quantity);
    }

    /**
     * `addStock`, not `returnStock`: an administrative increment is not a
     * refund.
     *
     * `returnStock` credits a hold that is being released - what a cart had
     * reserved stops being held the very moment it lands in the column, so the
     * total does not move and only the column's own ceiling applies. These
     * units were never held by anybody, so they have to leave the same room a
     * recount leaves: available plus what refundable carts hold has to fit
     * under the maximum. Taking the refund path here let `{"delta":1}` fill the
     * last slot a pending cart was going to need, and cancelling that cart
     * afterwards had nowhere to put its unit and rolled back.
     */
    public function test_a_positive_delta_adds_units_through_the_bounded_atomic_increment(): void
    {
        $product = $this->product();

        $read = $this->handler($product)(new AdjustProductStockCommand($product->id()->toString(), delta: 3));

        self::assertSame([['add', 3]], $this->movements);
        self::assertSame(8, $read->quantity);
    }

    public function test_a_negative_delta_removes_units_through_the_conditional_decrement(): void
    {
        $product = $this->product();

        $read = $this->handler($product)(new AdjustProductStockCommand($product->id()->toString(), delta: -2));

        self::assertSame([['reserve', 2]], $this->movements);
        self::assertSame(3, $read->quantity);
    }

    /** The available count never goes below zero; the database says no, not a check made earlier. */
    public function test_removing_more_than_the_available_units_is_a_conflict(): void
    {
        $product = $this->product();

        try {
            $this->handler($product)(new AdjustProductStockCommand($product->id()->toString(), delta: -9));
            self::fail('expected an exception');
        } catch (OutOfStockException $e) {
            self::assertStringContainsString('below zero', $e->getMessage());
            self::assertStringContainsString('5 unit(s)', $e->getMessage());
        }

        self::assertSame(5, $product->quantity()->asInt());
    }

    public function test_an_unknown_or_withdrawn_product_is_not_found(): void
    {
        $this->expectException(ProductNotFoundException::class);

        $this->handler(null)(new AdjustProductStockCommand(Uuid::uuid4()->toString(), quantity: 1));
    }

    #[DataProvider('shapesThatSayNothing')]
    public function test_exactly_one_of_quantity_or_delta_is_required(int|string|null $quantity, int|string|null $delta): void
    {
        $this->expectException(InvalidStockAdjustmentException::class);
        $this->expectExceptionMessage('exactly one');

        new AdjustProductStockCommand(Uuid::uuid4()->toString(), $quantity, $delta);
    }

    /**
     * @return iterable<string, array{int|string|null, int|string|null}>
     */
    public static function shapesThatSayNothing(): iterable
    {
        yield 'neither' => [null, null];
        yield 'both' => [3, 2];
    }

    public function test_a_delta_of_zero_is_refused(): void
    {
        $this->expectException(InvalidStockAdjustmentException::class);
        $this->expectExceptionMessage('changes nothing');

        new AdjustProductStockCommand(Uuid::uuid4()->toString(), delta: 0);
    }

    #[DataProvider('deltasOutOfRange')]
    public function test_a_delta_beyond_the_column_or_not_an_integer_is_refused(int|string $delta): void
    {
        $this->expectException(InvalidStockAdjustmentException::class);
        $this->expectExceptionMessage('between');

        new AdjustProductStockCommand(Uuid::uuid4()->toString(), delta: $delta);
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function deltasOutOfRange(): iterable
    {
        yield 'too large' => [Quantity::MAX_QUANTITY + 1];
        yield 'too small' => [-Quantity::MAX_QUANTITY - 1];
        yield 'word' => ['many'];
        yield 'decimal' => ['1.5'];
    }

    public function test_numeric_strings_are_understood(): void
    {
        self::assertSame(-3, (new AdjustProductStockCommand(Uuid::uuid4()->toString(), delta: '-3'))->delta());
        self::assertSame(7, (new AdjustProductStockCommand(Uuid::uuid4()->toString(), quantity: '7'))->quantity()?->asInt());
    }

    public function test_an_absolute_quantity_below_zero_is_refused(): void
    {
        $this->expectException(InvalidQuantityException::class);

        new AdjustProductStockCommand(Uuid::uuid4()->toString(), quantity: -1);
    }

    /**
     * Under the row lock, like every other writer that decides something from
     * the row it is about to change.
     *
     * Read without it, a withdrawal committing between the read and the
     * movement gave the wrong answer rather than a race: `reserveStock` carries
     * `deleted_at IS NULL`, so it changed nothing, and a zero-row result reads
     * here as "not enough units" - a 409 about the stock of a product that had
     * simply been taken out of the catalogue.
     */
    public function test_the_product_is_read_under_its_row_lock(): void
    {
        $product = $this->product();

        $products = $this->createMock(ProductRepository::class);
        $products->expects(self::never())->method('ofId');
        $products->expects(self::once())
            ->method('ofIdForUpdate')
            ->with(self::equalTo($product->id()))
            ->willReturn($product);
        $products->method('setStock')->willReturn(true);

        (new AdjustProductStockCommandHandler($products, new RecordingSession()))(
            new AdjustProductStockCommand($product->id()->toString(), quantity: 7),
        );
    }

    /**
     * The units carts hold are read with the product's row already held.
     *
     * That lock is what keeps the number true for the rest of the transaction:
     * a cart operation that changes what is held moves the available count by
     * the same units the other way, and that writes this row. Read before the
     * lock the figure could still move under the movement that uses it - by a
     * reservation that commits in between, which is the direction that lets an
     * increase through - and there is nothing to hold the carts with that does
     * not deadlock against one of the two cart writers.
     */
    public function test_the_units_carts_hold_are_read_under_the_product_lock(): void
    {
        $product = $this->product();

        $this->handler($product)(new AdjustProductStockCommand($product->id()->toString(), quantity: 7));

        self::assertSame(['lockProduct', 'readHeldUnits'], $this->reads);
    }

    /** And an increment takes the same order; it respects the same ceiling. */
    public function test_a_positive_delta_reads_them_in_the_same_order(): void
    {
        $product = $this->product();

        $this->handler($product)(new AdjustProductStockCommand($product->id()->toString(), delta: 3));

        self::assertSame(['lockProduct', 'readHeldUnits'], $this->reads);
    }

    private function product(): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('K3'),
            Name::fromString('Gafas'),
            Price::of('129.95', 'EUR'),
            new Quantity($this->available),
        );
    }

    /**
     * The stubs apply the movement to the in-memory product the way the real
     * repository refreshes the managed entity after its UPDATE.
     */
    private function handler(?Product $product): AdjustProductStockCommandHandler
    {
        $products = $this->createStub(ProductRepository::class);
        $products->method('ofIdForUpdate')->willReturnCallback(function () use ($product): ?Product {
            $this->reads[] = 'lockProduct';

            return $product;
        });
        $products->method('unitsHeldInRefundableCarts')->willReturnCallback(function (): int {
            $this->reads[] = 'readHeldUnits';

            return 0;
        });
        $products->method('setStock')->willReturnCallback(function (ProductId $id, Quantity $quantity) use ($product): bool {
            $this->movements[] = ['set', $quantity->asInt()];
            $product?->setQuantity($quantity);

            return null !== $product;
        });
        // Recorded although the handler no longer calls it, so a return to the
        // refund path shows up here as a movement of the wrong kind rather than
        // as an identical-looking pass.
        $products->method('returnStock')->willReturnCallback(function (ProductId $id, int $units) use ($product): void {
            $this->movements[] = ['return', $units];
            $product?->setQuantity(new Quantity($product->quantity()->asInt() + $units));
        });
        $products->method('addStock')->willReturnCallback(function (ProductId $id, int $units) use ($product): void {
            $this->movements[] = ['add', $units];
            $product?->setQuantity(new Quantity($product->quantity()->asInt() + $units));
        });
        $products->method('reserveStock')->willReturnCallback(function (ProductId $id, int $units) use ($product): bool {
            if (null === $product || $product->quantity()->asInt() < $units) {
                return false;
            }
            $this->movements[] = ['reserve', $units];
            $product->setQuantity(new Quantity($product->quantity()->asInt() - $units));

            return true;
        });

        return new AdjustProductStockCommandHandler($products, new RecordingSession());
    }
}
