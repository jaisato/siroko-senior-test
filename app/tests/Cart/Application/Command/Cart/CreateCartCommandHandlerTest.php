<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\CreateCartCommand;
use Siroko\Cart\Application\Command\Cart\CreateCartCommandHandler;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Exception\OutOfStockException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * Reservar el stock de varios productos es tomar varios cerrojos de fila, y el
 * orden en que se toman es lo único que decide si dos peticiones simultáneas
 * se interbloquean.
 */
final class CreateCartCommandHandlerTest extends TestCase
{
    /** @var list<string> productos reservados, en el orden en que se reservaron */
    private array $reserved = [];

    private RecordingSession $session;

    /** @var array<string, Product> */
    private array $catalogue = [];

    protected function setUp(): void
    {
        $this->reserved = [];
        $this->catalogue = [];
        $this->session = new RecordingSession();
    }

    /**
     * Three units used to be three rows with three ids; a line now holds its
     * units, so the cart has one line per product.
     */
    public function test_a_cart_is_created_pending_with_one_line_per_product_holding_its_units(): void
    {
        $product = $this->product('11111111-1111-4111-8111-111111111111');

        $units = [];
        $handler = $this->handler($units);

        $read = $handler(new CreateCartCommand([
            ['productId' => $product->id()->toString(), 'quantity' => 3],
        ]));

        self::assertTrue(Uuid::isValid($read->id));
        self::assertSame(CartStatus::PENDING, $read->status);
        self::assertCount(1, $read->items);
        self::assertSame(3, array_values($read->items)[0]->quantity);
        self::assertSame([$product->id()->toString() => 3], $units, 'all three units were reserved at once');
        self::assertSame(['begin', 'saveCart', 'commit'], $this->session->log, 'reservations and the cart share one transaction');
    }

    /** A request naming the same product twice ends up with one line for it. */
    public function test_lines_naming_the_same_product_fold_into_one(): void
    {
        $product = $this->product('11111111-1111-4111-8111-111111111111');

        $handler = $this->handler();

        $read = $handler(new CreateCartCommand([
            ['productId' => $product->id()->toString(), 'quantity' => 2],
            ['productId' => $product->id()->toString(), 'quantity' => 3],
        ]));

        self::assertCount(1, $read->items);
        self::assertSame(5, array_values($read->items)[0]->quantity);
        self::assertSame([$product->id()->toString(), $product->id()->toString()], $this->reserved, 'each line reserved its own units');
    }

    /**
     * The per-line cap applies to the merged line. The reservation has been
     * made by then; in production the transaction rolls it back.
     */
    public function test_lines_of_one_product_adding_up_to_more_than_a_line_holds_are_refused(): void
    {
        $product = $this->product('11111111-1111-4111-8111-111111111111');

        $handler = $this->handler();

        $this->expectException(InvalidQuantityException::class);

        $handler(new CreateCartCommand([
            ['productId' => $product->id()->toString(), 'quantity' => CreateCartCommand::MAX_ORDERED_QUANTITY],
            ['productId' => $product->id()->toString(), 'quantity' => 1],
        ]));
    }

    /**
     * Dos altas con los mismos productos en orden contrario reservaban cada una
     * en el orden en que llegaban en la petición: cada transacción bloqueaba su
     * primer producto y esperaba al que tenía la otra. MySQL aborta una de las
     * dos, y el bus de escritura no reintenta, así que una petición
     * perfectamente válida devolvía un 500.
     *
     * Recorriendo siempre los productos en el mismo orden no hay ciclo de
     * espera: la segunda espera a la primera y sigue.
     */
    public function test_products_are_reserved_in_a_stable_order_whatever_the_request_order(): void
    {
        $first = $this->product('11111111-1111-4111-8111-111111111111');
        $second = $this->product('22222222-2222-4222-8222-222222222222');
        $third = $this->product('33333333-3333-4333-8333-333333333333');

        $ascending = $this->handler();
        $ascending(new CreateCartCommand([
            ['productId' => $first->id()->toString(), 'quantity' => 1],
            ['productId' => $second->id()->toString(), 'quantity' => 1],
            ['productId' => $third->id()->toString(), 'quantity' => 1],
        ]));
        $ascendingOrder = $this->reserved;

        $this->reserved = [];
        $this->session = new RecordingSession();

        $descending = $this->handler();
        $descending(new CreateCartCommand([
            ['productId' => $third->id()->toString(), 'quantity' => 1],
            ['productId' => $second->id()->toString(), 'quantity' => 1],
            ['productId' => $first->id()->toString(), 'quantity' => 1],
        ]));

        self::assertSame(
            $ascendingOrder,
            $this->reserved,
            'both requests take the product locks in the same order',
        );
        self::assertSame(
            [
                $first->id()->toString(),
                $second->id()->toString(),
                $third->id()->toString(),
            ],
            $this->reserved,
        );
    }

    /** Las cantidades siguen yendo con su producto después de reordenar. */
    public function test_reordering_keeps_each_quantity_with_its_product(): void
    {
        $first = $this->product('11111111-1111-4111-8111-111111111111');
        $second = $this->product('22222222-2222-4222-8222-222222222222');

        $units = [];
        $handler = $this->handler($units);

        $handler(new CreateCartCommand([
            ['productId' => $second->id()->toString(), 'quantity' => 5],
            ['productId' => $first->id()->toString(), 'quantity' => 2],
        ]));

        // El orden lo fija el test de arriba; aquí sólo importa el
        // emparejamiento producto-cantidad tras reordenar.
        self::assertIsArray($units);
        ksort($units);

        self::assertSame(
            [
                $first->id()->toString() => 2,
                $second->id()->toString() => 5,
            ],
            $units,
        );
    }

    public function test_an_unknown_product_is_not_found_and_not_a_fatal(): void
    {
        $handler = $this->handler();

        $this->expectException(ProductNotFoundException::class);

        $handler(new CreateCartCommand([
            ['productId' => Uuid::uuid4()->toString(), 'quantity' => 1],
        ]));
    }

    public function test_a_product_without_enough_stock_is_refused(): void
    {
        $product = $this->product('11111111-1111-4111-8111-111111111111');

        $handler = $this->handler(available: false);

        $this->expectException(OutOfStockException::class);

        $handler(new CreateCartCommand([
            ['productId' => $product->id()->toString(), 'quantity' => 1],
        ]));
    }

    private function product(string $id): Product
    {
        $product = new Product(
            ProductId::fromString($id),
            ProductCode::fromString('ABC123'),
            Name::fromString('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity(50),
        );

        $this->catalogue[$product->id()->toString()] = $product;

        return $product;
    }

    /**
     * @param array<string, int>|null $units unidades reservadas por producto
     */
    private function handler(?array &$units = null, bool $available = true): CreateCartCommandHandler
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('nextIdentity')->willReturnCallback(
            static fn() => CartId::fromString(Uuid::uuid4()->toString()),
        );
        $carts->method('save')->willReturnCallback(function (): void {
            $this->session->log[] = 'saveCart';
        });

        $items = $this->createStub(CartItemRepository::class);
        $items->method('nextIdentity')->willReturnCallback(
            static fn() => ItemId::fromString(Uuid::uuid4()->toString()),
        );

        $products = $this->createStub(ProductRepository::class);
        $products->method('ofId')->willReturnCallback(
            fn(ProductId $id): ?Product => $this->catalogue[$id->toString()] ?? null,
        );
        $products->method('reserveStock')->willReturnCallback(
            function (ProductId $id, int $requested) use (&$units, $available): bool {
                if (!$available) {
                    return false;
                }

                $this->reserved[] = $id->toString();

                if (null !== $units) {
                    $units[$id->toString()] = $requested;
                }

                return true;
            },
        );

        return new CreateCartCommandHandler($carts, $items, $products, $this->session);
    }
}
