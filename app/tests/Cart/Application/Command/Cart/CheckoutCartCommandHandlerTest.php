<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\CheckoutCartCommand;
use Siroko\Cart\Application\Command\Cart\CheckoutCartCommandHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;

/**
 * Cobrar un carrito es leer su estado y escribirlo. Leyendo sin bloqueo, la
 * comprobación y la escritura son dos operaciones separadas y cualquier cosa
 * puede pasar entre medias:
 *
 * - dos checkouts simultáneos leen los dos el carrito pendiente y los dos lo
 *   cobran, de modo que el 409 que debía recibir el segundo nunca llega;
 * - un DELETE de línea en marcha también lo lee pendiente, así que el checkout
 *   confirma un carrito pagado que todavía contiene la línea y el borrado
 *   devuelve después al stock una unidad ya vendida.
 *
 * Por eso el carrito se carga con su fila bloqueada, y dentro de una
 * transacción: sin ella el bloqueo no dura hasta la escritura.
 */
final class CheckoutCartCommandHandlerTest extends TestCase
{
    private RecordingSession $session;

    protected function setUp(): void
    {
        $this->session = new RecordingSession();
    }

    public function test_a_pending_cart_becomes_paid(): void
    {
        $cart = $this->cart(CartStatus::PENDING);

        $handler = new CheckoutCartCommandHandler($this->carts($cart), $this->session);

        $read = $handler(new CheckoutCartCommand($cart->id()->toString()));

        self::assertSame(CartStatus::PAID, $cart->status()->toInt());
        self::assertSame(CartStatus::PAID, $read->status);
        self::assertSame($cart->id()->toString(), $read->id);
    }

    public function test_the_cart_is_read_under_a_row_lock_inside_the_transaction(): void
    {
        $cart = $this->cart(CartStatus::PENDING);

        $handler = new CheckoutCartCommandHandler($this->carts($cart), $this->session);

        $handler(new CheckoutCartCommand($cart->id()->toString()));

        self::assertSame(1, $this->session->transactions, 'exactly one transaction was opened');
        self::assertSame(
            ['begin', 'lockCart', 'saveCart', 'commit'],
            $this->session->log,
            'the lock and the write share one transaction',
        );
    }

    /** Cobrar dos veces es un conflicto del cliente, no un fallo del servidor. */
    public function test_a_cart_that_is_already_paid_is_refused_and_not_written(): void
    {
        $cart = $this->cart(CartStatus::PAID);

        $handler = new CheckoutCartCommandHandler($this->carts($cart), $this->session);

        try {
            $handler(new CheckoutCartCommand($cart->id()->toString()));
            self::fail('expected an exception');
        } catch (InvalidCartStatusException) {
        }

        self::assertNotContains('saveCart', $this->session->log);
    }

    public function test_an_unknown_cart_is_not_found(): void
    {
        $handler = new CheckoutCartCommandHandler($this->carts(null), $this->session);

        $this->expectException(CartNotFoundException::class);

        $handler(new CheckoutCartCommand(Uuid::uuid4()->toString()));
    }

    public function test_the_command_validates_its_identifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new CheckoutCartCommand('cart-1');
    }

    private function cart(int $status): Cart
    {
        return new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus($status));
    }

    private function carts(?Cart $cart): CartRepository
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('ofIdForUpdate')->willReturnCallback(
            function () use ($cart): ?Cart {
                $this->session->log[] = 'lockCart';

                return $cart;
            },
        );
        // El camino sin bloqueo no debe usarse.
        $carts->method('ofId')->willReturnCallback(
            static fn() => self::fail('the cart must be loaded with its row locked'),
        );
        $carts->method('save')->willReturnCallback(
            function (): void {
                $this->session->log[] = 'saveCart';
            },
        );

        return $carts;
    }
}
