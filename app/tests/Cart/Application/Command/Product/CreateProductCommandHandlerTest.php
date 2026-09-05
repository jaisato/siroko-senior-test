<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Product;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Product\CreateProductCommand;
use Siroko\Cart\Application\Command\Product\CreateProductCommandHandler;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\DuplicateProductCodeException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;

final class CreateProductCommandHandlerTest extends TestCase
{
    /** @var list<Product> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->saved = [];
    }

    public function test_it_saves_the_product_and_returns_its_read_model(): void
    {
        $handler = new CreateProductCommandHandler($this->products(codeTaken: false));

        $read = $handler(new CreateProductCommand('K3-BLACK', 'Gafas Siroko', '129.95', 'EUR', 12));

        self::assertCount(1, $this->saved);
        $product = $this->saved[0];

        self::assertSame('K3-BLACK', $product->code()->toString());
        self::assertSame('Gafas Siroko', $product->name()->toString());
        self::assertSame('129.95', $product->price()->amount());
        self::assertSame(12, $product->quantity()->asInt());

        self::assertSame($product->id()->toString(), $read->id);
        self::assertSame('Gafas Siroko', $read->name);
        self::assertSame('K3-BLACK', $read->code);
        self::assertSame("129,95\u{a0}€", $read->price);
        self::assertSame(12, $read->quantity);
    }

    public function test_every_product_gets_a_fresh_identity(): void
    {
        $handler = new CreateProductCommandHandler($this->products(codeTaken: false));

        $first = $handler(new CreateProductCommand('A', 'First product', '1.00', 'EUR', 1));
        $second = $handler(new CreateProductCommand('B', 'Second product', '1.00', 'EUR', 1));

        self::assertNotSame($first->id, $second->id);
        self::assertTrue(Uuid::isValid($first->id));
    }

    /** A code names one product; the database enforces it too, this is the readable answer. */
    public function test_a_code_already_in_use_is_refused_and_nothing_is_saved(): void
    {
        $handler = new CreateProductCommandHandler($this->products(codeTaken: true));

        try {
            $handler(new CreateProductCommand('TAKEN', 'Another product', '1.00', 'EUR', 1));
            self::fail('expected an exception');
        } catch (DuplicateProductCodeException $e) {
            self::assertStringContainsString('TAKEN', $e->getMessage());
        }

        self::assertSame([], $this->saved);
    }

    private function products(bool $codeTaken): ProductRepository
    {
        $products = $this->createStub(ProductRepository::class);
        $products->method('nextIdentity')->willReturnCallback(
            static fn() => ProductId::fromString(Uuid::uuid4()->toString()),
        );
        $products->method('existsWithCode')->willReturnCallback(
            static fn(ProductCode $code): bool => $codeTaken,
        );
        $products->method('save')->willReturnCallback(function (Product $product): void {
            $this->saved[] = $product;
        });

        return $products;
    }
}
