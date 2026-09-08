<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\Entity;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class ProductTest extends TestCase
{
    public function test_it_exposes_its_value_objects(): void
    {
        $id = ProductId::fromString(Uuid::uuid4()->toString());
        $price = Price::of('19.99', 'EUR');

        $product = new Product($id, ProductCode::fromString('K3'), Name::fromString('Gafas'), $price, new Quantity(3));

        self::assertSame($id, $product->id());
        self::assertSame('K3', $product->code()->toString());
        self::assertSame('Gafas', $product->name()->toString());
        self::assertSame($price, $product->price(), 'the domain Price is held as is, no persistence copy in between');
        self::assertSame(3, $product->quantity()->asInt());
    }

    public function test_a_product_without_a_quantity_has_no_stock(): void
    {
        $product = new Product(ProductId::fromString(Uuid::uuid4()->toString()), ProductCode::fromString('K3'), Name::fromString('Gafas'), Price::of('1', 'EUR'));

        self::assertSame(0, $product->quantity()->asInt());
    }

    public function test_price_and_quantity_can_change(): void
    {
        $product = new Product(ProductId::fromString(Uuid::uuid4()->toString()), ProductCode::fromString('K3'), Name::fromString('Gafas'), Price::of('1', 'EUR'));

        $product->setPrice(Price::of('2.50', 'EUR'));
        $product->setQuantity(new Quantity(9));

        self::assertSame('2.50', $product->price()->amount());
        self::assertSame(9, $product->quantity()->asInt());
    }

    public function test_name_and_code_can_change(): void
    {
        $product = new Product(ProductId::fromString(Uuid::uuid4()->toString()), ProductCode::fromString('K3'), Name::fromString('Gafas'), Price::of('1', 'EUR'));

        $product->rename(Name::fromString('Gafas Siroko K3'));
        $product->recode(ProductCode::fromString('K3-BLACK'));

        self::assertSame('Gafas Siroko K3', $product->name()->toString());
        self::assertSame('K3-BLACK', $product->code()->toString());
    }

    /** A withdrawn product keeps its row and its first withdrawal date. */
    public function test_a_product_can_be_withdrawn_once(): void
    {
        $product = new Product(ProductId::fromString(Uuid::uuid4()->toString()), ProductCode::fromString('K3'), Name::fromString('Gafas'), Price::of('1', 'EUR'));
        $first = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));

        self::assertFalse($product->isDeleted());
        self::assertNull($product->deletedAt());

        $product->delete($first);
        $product->delete($first->modify('+1 day'));

        self::assertTrue($product->isDeleted());
        self::assertSame($first, $product->deletedAt(), 'the first withdrawal stands');
        self::assertSame('K3', $product->code()->toString(), 'the code stays taken');
    }
}
