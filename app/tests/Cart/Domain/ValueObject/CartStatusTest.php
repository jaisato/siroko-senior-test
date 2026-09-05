<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\ValueObject\CartStatus;

final class CartStatusTest extends TestCase
{
    #[DataProvider('knownStatuses')]
    public function test_every_known_status_is_accepted(int $status): void
    {
        $cartStatus = new CartStatus($status);

        self::assertSame($status, $cartStatus->toInt());
        self::assertSame((string) $status, (string) $cartStatus);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function knownStatuses(): iterable
    {
        yield 'pending' => [CartStatus::PENDING];
        yield 'paid' => [CartStatus::PAID];
        yield 'delivered' => [CartStatus::DELIVERED];
        yield 'canceled' => [CartStatus::CANCELED];
    }

    public function test_named_constructors(): void
    {
        self::assertTrue(CartStatus::pending()->isPending());
        self::assertSame(CartStatus::PENDING, CartStatus::pending()->toInt());
        self::assertFalse(CartStatus::paid()->isPending());
        self::assertSame(CartStatus::PAID, CartStatus::paid()->toInt());
    }

    public function test_equality(): void
    {
        self::assertTrue(CartStatus::paid()->equals(new CartStatus(CartStatus::PAID)));
        self::assertFalse(CartStatus::paid()->equals(CartStatus::pending()));
    }

    #[DataProvider('unknownStatuses')]
    public function test_an_unknown_status_is_rejected(int $status): void
    {
        $this->expectException(InvalidCartStatusException::class);

        new CartStatus($status);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unknownStatuses(): iterable
    {
        yield 'zero' => [0];
        yield 'five' => [5];
        yield 'negative' => [-1];
    }
}
