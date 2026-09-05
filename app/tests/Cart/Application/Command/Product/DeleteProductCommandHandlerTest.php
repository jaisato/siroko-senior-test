<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Product;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Product\DeleteProductCommand;
use Siroko\Cart\Application\Command\Product\DeleteProductCommandHandler;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Tests\Cart\Application\Command\Cart\RecordingSession;
use Symfony\Component\Clock\MockClock;

final class DeleteProductCommandHandlerTest extends TestCase
{
    private RecordingSession $session;

    private bool $saved = false;

    protected function setUp(): void
    {
        $this->session = new RecordingSession();
        $this->saved = false;
    }

    public function test_it_withdraws_the_product_at_the_clock_time_under_the_row_lock(): void
    {
        $product = new Product(ProductId::fromString(Uuid::uuid4()->toString()), ProductCode::fromString('K3'), Name::fromString('Gafas'), Price::of('10.00', 'EUR'));

        $this->handler($product)(new DeleteProductCommand($product->id()->toString()));

        self::assertTrue($product->isDeleted());
        self::assertSame('2026-09-06T10:00:00+00:00', $product->deletedAt()?->format(\DateTimeInterface::RFC3339));
        self::assertTrue($this->saved);
        self::assertSame(1, $this->session->transactions);
    }

    /** Once withdrawn the product is not found, so a second delete is a 404, like any other read of it. */
    public function test_an_unknown_or_already_withdrawn_product_is_not_found(): void
    {
        $this->expectException(ProductNotFoundException::class);

        $this->handler(null)(new DeleteProductCommand(Uuid::uuid4()->toString()));
    }

    public function test_the_command_validates_its_identifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new DeleteProductCommand('product-1');
    }

    private function handler(?Product $locked): DeleteProductCommandHandler
    {
        $products = $this->createStub(ProductRepository::class);
        $products->method('ofIdForUpdate')->willReturn($locked);
        $products->method('ofId')->willReturnCallback(static fn() => self::fail('the product must be loaded with its row locked'));
        $products->method('save')->willReturnCallback(function (): void {
            $this->saved = true;
        });

        return new DeleteProductCommandHandler($products, $this->session, new MockClock('2026-09-06 10:00:00', 'UTC'));
    }
}
