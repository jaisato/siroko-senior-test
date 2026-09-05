<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\DeliverCartCommand;
use Siroko\Cart\Application\Command\Cart\DeliverCartCommandHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;

final class DeliverCartCommandHandlerTest extends TestCase
{
    private RecordingSession $session;

    protected function setUp(): void
    {
        $this->session = new RecordingSession();
    }

    public function test_a_paid_cart_becomes_delivered_under_the_row_lock(): void
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::paid());

        $read = $this->handler($cart)(new DeliverCartCommand($cart->id()->toString()));

        self::assertSame(CartStatus::DELIVERED, $cart->status()->toInt());
        self::assertSame(CartStatus::DELIVERED, $read->status);
        self::assertSame(['begin', 'lockCart', 'saveCart', 'commit'], $this->session->log);
    }

    public function test_a_cart_that_is_not_paid_is_refused_and_not_written(): void
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());

        try {
            $this->handler($cart)(new DeliverCartCommand($cart->id()->toString()));
            self::fail('expected an exception');
        } catch (InvalidCartStatusException) {
        }

        self::assertTrue($cart->isPending());
        self::assertNotContains('saveCart', $this->session->log);
    }

    public function test_an_unknown_cart_is_not_found(): void
    {
        $this->expectException(CartNotFoundException::class);

        $this->handler(null)(new DeliverCartCommand(Uuid::uuid4()->toString()));
    }

    public function test_the_command_validates_its_identifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new DeliverCartCommand('cart-1');
    }

    private function handler(?Cart $cart): DeliverCartCommandHandler
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('ofIdForUpdate')->willReturnCallback(function () use ($cart): ?Cart {
            $this->session->log[] = 'lockCart';

            return $cart;
        });
        $carts->method('ofId')->willReturnCallback(static fn() => self::fail('the cart must be loaded with its row locked'));
        $carts->method('save')->willReturnCallback(function (): void {
            $this->session->log[] = 'saveCart';
        });

        return new DeliverCartCommandHandler($carts, $this->session);
    }
}
