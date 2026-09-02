<?php

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\AddCartProductCommand;
use Siroko\Cart\Application\Command\Cart\AddCartProductCommandHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\OutOfStockException;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reservar stock tiene que ser un ajuste relativo y condicional.
 *
 * Un `setQuantity()` con un valor absoluto calculado sobre una lectura previa
 * borra cualquier devolución que confirme otra petición entre medias -stock 4,
 * un borrado lo sube a 5, y este alta lo deja en 3 en vez de en 4-. Y comprobar
 * el stock aparte de restarlo deja que dos altas simultáneas pasen las dos la
 * comprobación y vendan de más.
 */
final class AddCartProductCommandHandlerTest extends TestCase
{
    private RecordingSession $session;

    protected function setUp(): void
    {
        $this->session = new RecordingSession();
    }

    public function test_reserves_one_unit_atomically_and_adds_the_item(): void
    {
        $reserved = [];
        $product = $this->product(quantity: 4);

        $handler = $this->handler($product, $reserved, available: true);

        $handler(new AddCartProductCommand($this->cartId(), $product->id()->toString()));

        self::assertSame([[$product->id()->toString(), 1]], $reserved);
    }

    /**
     * El stock no se toca en memoria: hacerlo devolvería a Doctrine un valor
     * absoluto en el flush y volvería a pisar las devoluciones concurrentes,
     * que es justo lo que la reserva relativa evita.
     */
    public function test_does_not_write_an_absolute_quantity_back_to_the_product(): void
    {
        $reserved = [];
        $product = $this->product(quantity: 4);

        $handler = $this->handler($product, $reserved, available: true);

        $handler(new AddCartProductCommand($this->cartId(), $product->id()->toString()));

        self::assertSame(4, $product->quantity()->asInt());
    }

    /**
     * Sin stock, la reserva no se hace y el cliente recibe un 409, no el 400
     * que salía cuando la resta llegaba a `new Quantity(-1)`.
     */
    public function test_refuses_when_there_is_no_stock_left(): void
    {
        $reserved = [];
        $product = $this->product(quantity: 0);

        $handler = $this->handler($product, $reserved, available: false);

        $this->expectException(OutOfStockException::class);

        $handler(new AddCartProductCommand($this->cartId(), $product->id()->toString()));
    }

    public function test_the_reservation_and_the_cart_write_share_one_transaction(): void
    {
        $reserved = [];
        $product = $this->product(quantity: 4);

        $handler = $this->handler($product, $reserved, available: true);

        $handler(new AddCartProductCommand($this->cartId(), $product->id()->toString()));

        self::assertSame(1, $this->session->transactions);
        self::assertSame(['begin', 'lockCart', 'reserveStock', 'saveCart', 'commit'], $this->session->log);
    }

    /**
     * El carrito se bloquea antes de tocar el producto.
     *
     * `cart_item` tiene clave ajena NOT NULL a `cart`, así que insertar la
     * línea toma un cerrojo compartido sobre la fila del carrito. Reservando
     * primero, este handler tomaba producto -> carrito mientras que borrar una
     * línea toma carrito -> producto: un alta y un borrado solapados sobre el
     * mismo carrito y producto se interbloqueaban, MySQL abortaba uno y el bus
     * de escritura no reintenta, así que salía un 500.
     */
    public function test_the_cart_is_locked_before_the_product(): void
    {
        $reserved = [];
        $product = $this->product(quantity: 4);

        $handler = $this->handler($product, $reserved, available: true);

        $handler(new AddCartProductCommand($this->cartId(), $product->id()->toString()));

        self::assertSame(
            ['begin', 'lockCart', 'reserveStock'],
            array_slice($this->session->log, 0, 3),
            'the cart lock is taken first, and inside the transaction',
        );
    }

    public function test_an_unknown_cart_is_a_404(): void
    {
        $reserved = [];
        $product = $this->product(quantity: 4);

        $handler = $this->handler($product, $reserved, available: true, cartExists: false);

        $this->expectException(NotFoundHttpException::class);

        $handler(new AddCartProductCommand($this->cartId(), $product->id()->toString()));
    }

    private function cartId(): string
    {
        return $this->cart->id()->toString();
    }

    private Cart $cart;

    /**
     * @param list<array{0:string,1:int}> $reserved
     */
    private function handler(
        Product $product,
        array &$reserved,
        bool $available,
        bool $cartExists = true,
    ): AddCartProductCommandHandler {
        $this->cart = new Cart(
            CartId::fromString(Uuid::uuid4()->toString()),
            new CartStatus(CartStatus::PENDING),
        );

        $carts = $this->createStub(CartRepository::class);
        $carts->method('ofIdForUpdate')->willReturnCallback(
            function () use ($cartExists): ?Cart {
                $this->session->log[] = 'lockCart';

                return $cartExists ? $this->cart : null;
            }
        );
        // El camino sin bloqueo no debe usarse.
        $carts->method('ofId')->willReturnCallback(
            static fn () => self::fail('the cart must be loaded with its row locked')
        );
        $carts->method('save')->willReturnCallback(function (): void {
            $this->session->log[] = 'saveCart';
        });

        $products = $this->createStub(ProductRepository::class);
        $products->method('ofId')->willReturn($product);
        $products->method('reserveStock')->willReturnCallback(
            function ($id, int $units) use (&$reserved, $available): bool {
                $this->session->log[] = 'reserveStock';
                if (!$available) {
                    return false;
                }
                $reserved[] = [$id->toString(), $units];

                return true;
            }
        );
        $products->method('save')->willReturnCallback(
            static fn () => self::fail('stock must not be written back as an absolute value')
        );

        $items = $this->createStub(CartItemRepository::class);
        $items->method('nextIdentity')->willReturnCallback(
            static fn () => ItemId::fromString(Uuid::uuid4()->toString())
        );

        return new AddCartProductCommandHandler($carts, $products, $items, $this->session);
    }

    private function product(int $quantity): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            new ProductCode('ABC123'),
            new Name('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity($quantity),
        );
    }
}
