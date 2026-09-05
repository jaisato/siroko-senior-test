<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Exception\InvalidProductCriteriaException;
use Siroko\Cart\Domain\Repository\ProductCriteria;
use Siroko\Cart\Domain\Repository\ProductSort;

/**
 * The filters of a product listing are validated once, where they are built;
 * the repository trusts them.
 */
final class ProductCriteriaTest extends TestCase
{
    public function test_no_criteria_lists_everything_by_name(): void
    {
        $criteria = ProductCriteria::all();

        self::assertTrue($criteria->isEmpty());
        self::assertNull($criteria->text);
        self::assertNull($criteria->minPrice);
        self::assertNull($criteria->maxPrice);
        self::assertNull($criteria->inStock);
        self::assertSame(ProductSort::NameAsc, $criteria->sort);
        self::assertTrue(ProductCriteria::of()->isEmpty(), 'nothing given is the same as all()');
    }

    public function test_it_keeps_the_filters_it_is_given(): void
    {
        $criteria = ProductCriteria::of('  gafas ', '10', '99.99', true, '-price');

        self::assertFalse($criteria->isEmpty());
        self::assertSame('gafas', $criteria->text, 'surrounding whitespace is not part of a search');
        self::assertSame('10', $criteria->minPrice);
        self::assertSame('99.99', $criteria->maxPrice);
        self::assertTrue($criteria->inStock);
        self::assertSame(ProductSort::PriceDesc, $criteria->sort);
    }

    public function test_blank_values_mean_no_filter(): void
    {
        $criteria = ProductCriteria::of('   ', '', ' ', null, null);

        self::assertTrue($criteria->isEmpty());
    }

    public function test_a_search_text_has_a_length_limit(): void
    {
        self::assertSame(str_repeat('a', ProductCriteria::MAX_TEXT_LENGTH), ProductCriteria::of(str_repeat('a', ProductCriteria::MAX_TEXT_LENGTH))->text);

        $this->expectException(InvalidProductCriteriaException::class);
        $this->expectExceptionMessage('at most');

        ProductCriteria::of(str_repeat('a', ProductCriteria::MAX_TEXT_LENGTH + 1));
    }

    #[DataProvider('malformedAmounts')]
    public function test_a_price_bound_must_be_a_non_negative_amount(string $amount): void
    {
        $this->expectException(InvalidProductCriteriaException::class);
        $this->expectExceptionMessage('"minPrice"');

        ProductCriteria::of(minPrice: $amount);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedAmounts(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'word' => ['ten'];
        yield 'too many decimals' => ['1.00001'];
        yield 'comma' => ['1,5'];
        yield 'trailing garbage' => ['10abc'];
    }

    public function test_max_price_is_validated_under_its_own_name(): void
    {
        $this->expectException(InvalidProductCriteriaException::class);
        $this->expectExceptionMessage('"maxPrice"');

        ProductCriteria::of(maxPrice: 'x');
    }

    public function test_an_inverted_price_range_is_refused(): void
    {
        self::assertSame('10', ProductCriteria::of(minPrice: '10', maxPrice: '10')->maxPrice, 'equal bounds are a point, and fine');

        $this->expectException(InvalidProductCriteriaException::class);
        $this->expectExceptionMessage('cannot be greater');

        ProductCriteria::of(minPrice: '10.01', maxPrice: '10');
    }

    public function test_an_unknown_sort_is_refused_and_the_message_lists_the_known_ones(): void
    {
        try {
            ProductCriteria::of(sort: 'popularity');
            self::fail('expected an exception');
        } catch (InvalidProductCriteriaException $e) {
            foreach (ProductSort::values() as $value) {
                self::assertStringContainsString($value, $e->getMessage());
            }
        }
    }

    #[DataProvider('sorts')]
    public function test_every_sort_names_its_field_and_direction(string $value, string $field, bool $descending): void
    {
        $sort = ProductSort::from($value);

        self::assertSame($field, $sort->field());
        self::assertSame($descending, $sort->isDescending());
        self::assertSame($sort, ProductCriteria::of(sort: $value)->sort);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function sorts(): iterable
    {
        yield 'name' => ['name', 'name', false];
        yield '-name' => ['-name', 'name', true];
        yield 'price' => ['price', 'price', false];
        yield '-price' => ['-price', 'price', true];
        yield 'code' => ['code', 'code', false];
        yield '-code' => ['-code', 'code', true];
    }
}
