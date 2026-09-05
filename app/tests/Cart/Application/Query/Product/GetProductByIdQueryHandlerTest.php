<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Query\Product;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Query\Product\GetProductByIdQuery;
use Siroko\Cart\Application\Query\Product\GetProductByIdQueryHandler;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class GetProductByIdQueryHandlerTest extends TestCase
{
    public function test_it_reads_a_product(): void
    {
        $product = new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('K3'),
            Name::fromString('Gafas'),
            Price::of('129.95', 'EUR'),
            new Quantity(3),
        );

        $handler = new GetProductByIdQueryHandler($this->products($product));

        $read = $handler(new GetProductByIdQuery($product->id()->toString()));

        self::assertSame($product->id()->toString(), $read->id);
        self::assertSame('Gafas', $read->name);
        self::assertSame('K3', $read->code);
        self::assertSame("129,95\u{a0}€", $read->price);
        self::assertSame(3, $read->quantity);
    }

    public function test_an_unknown_product_is_not_found(): void
    {
        $handler = new GetProductByIdQueryHandler($this->products(null));

        $this->expectException(ProductNotFoundException::class);

        $handler(new GetProductByIdQuery(Uuid::uuid4()->toString()));
    }

    public function test_the_query_validates_its_identifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new GetProductByIdQuery('123');
    }

    private function products(?Product $product): ProductRepository
    {
        $products = $this->createStub(ProductRepository::class);
        $products->method('ofId')->willReturn($product);

        return $products;
    }
}
