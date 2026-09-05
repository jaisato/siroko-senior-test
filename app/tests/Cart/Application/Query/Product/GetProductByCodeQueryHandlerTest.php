<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Query\Product;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Query\Product\GetProductByCodeQuery;
use Siroko\Cart\Application\Query\Product\GetProductByCodeQueryHandler;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidProductCodeException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class GetProductByCodeQueryHandlerTest extends TestCase
{
    public function test_it_reads_a_product_by_its_code(): void
    {
        $product = new Product(ProductId::fromString(Uuid::uuid4()->toString()), ProductCode::fromString('K3'), Name::fromString('Gafas'), Price::of('129.95', 'EUR'), new Quantity(3));
        $products = $this->createStub(ProductRepository::class);
        $products->method('ofCode')->willReturnCallback(static fn(ProductCode $code): ?Product => $code->equals(ProductCode::fromString('K3')) ? $product : null);

        $read = (new GetProductByCodeQueryHandler($products))(new GetProductByCodeQuery(' K3 '));

        self::assertSame($product->id()->toString(), $read->id);
        self::assertSame('K3', $read->code);
        self::assertSame(3, $read->quantity);
    }

    public function test_an_unknown_code_is_not_found_and_the_message_names_it(): void
    {
        $products = $this->createStub(ProductRepository::class);
        $products->method('ofCode')->willReturn(null);

        try {
            (new GetProductByCodeQueryHandler($products))(new GetProductByCodeQuery('NOPE'));
            self::fail('expected an exception');
        } catch (ProductNotFoundException $e) {
            self::assertStringContainsString('NOPE', $e->getMessage());
        }
    }

    public function test_the_query_validates_the_code(): void
    {
        $this->expectException(InvalidProductCodeException::class);

        new GetProductByCodeQuery(str_repeat('x', 51));
    }
}
