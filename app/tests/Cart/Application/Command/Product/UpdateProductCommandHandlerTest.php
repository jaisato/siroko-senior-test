<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Product;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Product\UpdateProductCommand;
use Siroko\Cart\Application\Command\Product\UpdateProductCommandHandler;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\DuplicateProductCodeException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\InvalidPriceException;
use Siroko\Cart\Domain\Exception\InvalidProductUpdateException;
use Siroko\Cart\Domain\Exception\NameInvalidLengthException;
use Siroko\Cart\Domain\Exception\ProductIsInAPendingCartException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Siroko\Tests\Cart\Application\Command\Cart\RecordingSession;

final class UpdateProductCommandHandlerTest extends TestCase
{
    /** @var list<string> codes the catalogue already holds */
    private array $takenCodes = [];

    /** Whether a pending cart holds the product being updated. */
    private bool $heldByAPendingCart = false;

    private RecordingSession $session;

    protected function setUp(): void
    {
        $this->takenCodes = [];
        $this->heldByAPendingCart = false;
        $this->session = new RecordingSession();
    }

    /**
     * A cart line points at the product rather than holding a copy of its
     * price, so a currency change under a pending cart breaks that cart's
     * "one currency" rule after the fact: subtotal() throws from then on,
     * reading the cart answers 409 and checkout rolls back until it is
     * cancelled or expires. The customer cannot repair it; the change is
     * refused instead.
     */
    public function test_the_currency_cannot_change_under_a_pending_cart(): void
    {
        $this->heldByAPendingCart = true;
        $product = $this->product();

        $this->expectException(ProductIsInAPendingCartException::class);
        $this->expectExceptionMessage('cannot change from EUR to USD');

        ($this->handler($product))(new UpdateProductCommand($product->id()->toString(), priceAmount: '19.99', priceCurrency: 'USD'));
    }

    /** The amount may move all it likes; it is the currency the cart counts on. */
    public function test_the_amount_may_change_under_a_pending_cart(): void
    {
        $this->heldByAPendingCart = true;
        $product = $this->product();

        ($this->handler($product))(new UpdateProductCommand($product->id()->toString(), priceAmount: '29.99', priceCurrency: 'EUR'));

        self::assertSame('29.99', $product->price()->amount());
    }

    /** With no cart counting on it, a product may be repriced in any currency. */
    public function test_the_currency_changes_freely_when_no_cart_holds_the_product(): void
    {
        $product = $this->product();

        ($this->handler($product))(new UpdateProductCommand($product->id()->toString(), priceAmount: '19.99', priceCurrency: 'USD'));

        self::assertSame('USD', $product->price()->currency()->getCurrencyCode());
    }

    public function test_it_changes_only_the_fields_that_were_sent(): void
    {
        $product = $this->product();

        $read = $this->handler($product)(new UpdateProductCommand($product->id()->toString(), name: 'Gafas Siroko K3'));

        self::assertSame('Gafas Siroko K3', $product->name()->toString());
        self::assertSame('K3', $product->code()->toString(), 'untouched');
        self::assertSame('129.95', $product->price()->amount(), 'untouched');
        self::assertSame(12, $product->quantity()->asInt(), 'stock is never part of an update');
        self::assertSame('Gafas Siroko K3', $read->name);
    }

    public function test_it_changes_code_and_price_together_under_the_row_lock(): void
    {
        $product = $this->product();

        $this->handler($product)(new UpdateProductCommand($product->id()->toString(), code: 'K3-BLACK', priceAmount: '99.00', priceCurrency: 'EUR'));

        self::assertSame('K3-BLACK', $product->code()->toString());
        self::assertSame('99.00', $product->price()->amount());
        self::assertSame(['begin', 'lock', 'existsWithCode', 'save', 'commit'], $this->session->log);
    }

    public function test_a_code_already_taken_by_another_product_is_a_conflict_and_nothing_is_written(): void
    {
        $product = $this->product();
        $this->takenCodes[] = 'TAKEN';

        try {
            $this->handler($product)(new UpdateProductCommand($product->id()->toString(), code: 'TAKEN', name: 'New name'));
            self::fail('expected an exception');
        } catch (DuplicateProductCodeException) {
        }

        self::assertSame('K3', $product->code()->toString());
        self::assertSame('Gafas', $product->name()->toString(), 'the update is all or nothing');
        self::assertNotContains('save', $this->session->log);
    }

    /** Sending the product's own code is not a change and needs no uniqueness check. */
    public function test_keeping_the_same_code_is_not_a_conflict(): void
    {
        $product = $this->product();
        $this->takenCodes[] = 'K3';

        $this->handler($product)(new UpdateProductCommand($product->id()->toString(), code: 'K3'));

        self::assertNotContains('existsWithCode', $this->session->log);
        self::assertContains('save', $this->session->log);
    }

    public function test_an_unknown_or_withdrawn_product_is_not_found(): void
    {
        $this->expectException(ProductNotFoundException::class);

        $this->handler(null)(new UpdateProductCommand(Uuid::uuid4()->toString(), name: 'Whatever'));
    }

    public function test_an_update_that_changes_nothing_is_refused(): void
    {
        $this->expectException(InvalidProductUpdateException::class);
        $this->expectExceptionMessage('at least one');

        new UpdateProductCommand(Uuid::uuid4()->toString());
    }

    public function test_half_a_price_is_refused(): void
    {
        $this->expectException(InvalidProductUpdateException::class);
        $this->expectExceptionMessage('amount');

        new UpdateProductCommand(Uuid::uuid4()->toString(), priceAmount: '10.00');
    }

    public function test_the_command_validates_its_values(): void
    {
        try {
            new UpdateProductCommand('nope', name: 'Valid name');
            self::fail('expected an exception');
        } catch (InvalidIdentifierException) {
        }

        try {
            new UpdateProductCommand(Uuid::uuid4()->toString(), name: 'ab');
            self::fail('expected an exception');
        } catch (NameInvalidLengthException) {
        }

        $this->expectException(InvalidPriceException::class);

        new UpdateProductCommand(Uuid::uuid4()->toString(), priceAmount: '-1', priceCurrency: 'EUR');
    }

    private function product(): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('K3'),
            Name::fromString('Gafas'),
            Price::of('129.95', 'EUR'),
            new Quantity(12),
        );
    }

    private function handler(?Product $locked): UpdateProductCommandHandler
    {
        $products = $this->createStub(ProductRepository::class);
        $products->method('ofIdForUpdate')->willReturnCallback(function () use ($locked): ?Product {
            $this->session->log[] = 'lock';

            return $locked;
        });
        $products->method('ofId')->willReturnCallback(static fn() => self::fail('the product must be loaded with its row locked'));
        $products->method('existsWithCode')->willReturnCallback(function (ProductCode $code): bool {
            $this->session->log[] = 'existsWithCode';

            return \in_array($code->toString(), $this->takenCodes, true);
        });
        $products->method('save')->willReturnCallback(function (): void {
            $this->session->log[] = 'save';
        });

        $carts = $this->createStub(CartRepository::class);
        $carts->method('anyPendingHolds')->willReturnCallback(fn(): bool => $this->heldByAPendingCart);

        return new UpdateProductCommandHandler($products, $carts, $this->session);
    }
}
