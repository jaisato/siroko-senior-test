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
use Symfony\Component\Clock\MockClock;

/**
 * Reservar el stock de varios productos es tomar varios cerrojos de fila, y el
 * orden en que se toman es lo único que decide si dos peticiones simultáneas
 * se interbloquean.
 */
final class CreateCartCommandHandlerTest extends TestCase
{
    /** @var list<string> productos reservados, en el orden en que se reservaron */
    private array $reserved = [];

    /** @var list<string> productos bloqueados, en el orden en que se bloquearon */
    private array $lockedProducts = [];

    private RecordingSession $session;

    /** @var array<string, Product> */
    private array $catalogue = [];

    protected function setUp(): void
    {
        $this->reserved = [];
        $this->lockedProducts = [];
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
        self::assertSame(3, $read->items[0]->quantity);
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
        self::assertSame(5, $read->items[0]->quantity);
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

    /** The reservation deadline is the clock's now plus the configured TTL, never the wall clock. */
    public function test_a_new_cart_is_dated_by_the_clock_and_expires_after_the_ttl(): void
    {
        $product = $this->product('11111111-1111-4111-8111-111111111111');

        $handler = $this->handler(clock: new MockClock('2026-09-06 10:00:00', 'UTC'), ttlSeconds: 900);

        $read = $handler(new CreateCartCommand([
            ['productId' => $product->id()->toString(), 'quantity' => 1],
        ]));

        self::assertSame('2026-09-06T10:00:00+00:00', $read->createdAt);
        self::assertSame('2026-09-06T10:15:00+00:00', $read->expiresAt);
    }

    public function test_a_ttl_below_one_second_is_a_configuration_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->handler(ttlSeconds: 0);
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

    /**
     * Cada producto se lee con su fila bloqueada, y en orden de id.
     *
     * Leído sin cerrojo, `DELETE /v1/products/{id}` -que sí bloquea- podía
     * confirmarse entre esa lectura y la reserva: `reserveStock()` filtra por
     * `deleted_at IS NULL`, así que devolvía false y el cliente recibía un 409
     * "sin stock" por un producto retirado del catálogo, cuando lo que le
     * corresponde es el 404 que el propio handler acaba de descartar.
     */
    public function test_products_are_read_under_their_row_lock_in_id_order(): void
    {
        $second = $this->product('22222222-2222-4222-8222-222222222222');
        $first = $this->product('11111111-1111-4111-8111-111111111111');

        $this->handler()(new CreateCartCommand([
            ['productId' => $second->id()->toString(), 'quantity' => 1],
            ['productId' => $first->id()->toString(), 'quantity' => 1],
        ]));

        self::assertSame(
            [$first->id()->toString(), $second->id()->toString()],
            $this->lockedProducts,
            'el cerrojo se toma antes de reservar, y en el mismo orden que la reserva',
        );
        self::assertSame($this->lockedProducts, $this->reserved);
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
    private function handler(?array &$units = null, bool $available = true, ?MockClock $clock = null, int $ttlSeconds = 1800): CreateCartCommandHandler
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
        // The handler reads each product under its row lock, which is what
        // keeps a withdrawal from slipping between the read and the reserve.
        $products->method('ofIdForUpdate')->willReturnCallback(
            function (ProductId $id): ?Product {
                $this->lockedProducts[] = $id->toString();

                return $this->catalogue[$id->toString()] ?? null;
            },
        );
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

        return new CreateCartCommandHandler($carts, $items, $products, $this->session, $clock ?? new MockClock(), $ttlSeconds);
    }
}
