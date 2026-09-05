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

    private int $available = 5;

    protected function setUp(): void
    {
        $this->movements = [];
        $this->available = 5;
    }

    public function test_an_absolute_quantity_is_a_recount(): void
    {
        $product = $this->product();

        $read = $this->handler($product)(new AdjustProductStockCommand($product->id()->toString(), quantity: 40));

        self::assertSame([['set', 40]], $this->movements);
        self::assertSame(40, $read->quantity);
    }

    public function test_a_positive_delta_returns_units_through_the_atomic_increment(): void
    {
        $product = $this->product();

        $read = $this->handler($product)(new AdjustProductStockCommand($product->id()->toString(), delta: 3));

        self::assertSame([['return', 3]], $this->movements);
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
        $products->method('ofId')->willReturn($product);
        $products->method('setStock')->willReturnCallback(function (ProductId $id, Quantity $quantity) use ($product): bool {
            $this->movements[] = ['set', $quantity->asInt()];
            $product?->setQuantity($quantity);

            return null !== $product;
        });
        $products->method('returnStock')->willReturnCallback(function (ProductId $id, int $units) use ($product): void {
            $this->movements[] = ['return', $units];
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
