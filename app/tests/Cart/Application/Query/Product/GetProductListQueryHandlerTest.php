<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Query\Product;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Query\Product\GetProductListQuery;
use Siroko\Cart\Application\Query\Product\GetProductListQueryHandler;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;

final class GetProductListQueryHandlerTest extends TestCase
{
    public function test_it_returns_the_requested_page_with_the_size_of_the_catalogue(): void
    {
        $asked = null;
        $handler = new GetProductListQueryHandler($this->products([$this->product('Apple'), $this->product('Banana')], total: 7, asked: $asked));

        $collection = $handler(new GetProductListQuery(2, 2));

        self::assertSame([2, 2], $asked, 'the repository is asked for exactly that page');
        self::assertSame(['Apple', 'Banana'], array_map(static fn($p) => $p->name, $collection->products));
        self::assertSame(2, $collection->page);
        self::assertSame(2, $collection->pageSize);
        self::assertSame(7, $collection->total);
        self::assertSame(4, $collection->pages);
    }

    /** An empty catalogue is an empty list, not `{}`. */
    public function test_an_empty_catalogue_is_an_empty_page(): void
    {
        $asked = null;
        $handler = new GetProductListQueryHandler($this->products([], total: 0, asked: $asked));

        $collection = $handler(new GetProductListQuery(1, 20));

        self::assertSame([], $collection->products);
        self::assertSame(0, $collection->total);
        self::assertSame(0, $collection->pages);
        self::assertSame('{"products":[],"page":1,"pageSize":20,"total":0,"pages":0}', json_encode($collection, \JSON_THROW_ON_ERROR));
    }

    public function test_the_query_refuses_pages_before_the_first(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GetProductListQuery(0, 10);
    }

    public function test_the_query_refuses_empty_pages(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GetProductListQuery(1, 0);
    }

    public function test_the_query_refuses_pages_bigger_than_the_maximum(): void
    {
        self::assertSame(GetProductListQuery::MAX_PAGE_SIZE, (new GetProductListQuery(1, GetProductListQuery::MAX_PAGE_SIZE))->pageSize);

        $this->expectException(\InvalidArgumentException::class);

        new GetProductListQuery(1, GetProductListQuery::MAX_PAGE_SIZE + 1);
    }

    /**
     * @param list<Product>        $page
     * @param array{int, int}|null $asked
     */
    private function products(array $page, int $total, ?array &$asked): ProductRepository
    {
        $products = $this->createStub(ProductRepository::class);
        $products->method('findAll')->willReturnCallback(static function (int $pageNumber, int $pageSize) use ($page, &$asked): array {
            $asked = [$pageNumber, $pageSize];

            return $page;
        });
        $products->method('countAll')->willReturn($total);

        return $products;
    }

    private function product(string $name): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('SKU'),
            Name::fromString($name),
            Price::of('1.00', 'EUR'),
        );
    }
}
