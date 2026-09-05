<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Query\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Query\Cart\GetCartByIdQuery;
use Siroko\Cart\Application\Query\Cart\GetCartByIdQueryHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;

final class GetCartByIdQueryHandlerTest extends TestCase
{
    public function test_it_reads_a_cart_with_its_lines(): void
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('K3'),
            Name::fromString('Gafas'),
            Price::of('10.00', 'EUR'),
        ));
        $cart->addItem($item);

        $handler = new GetCartByIdQueryHandler($this->carts($cart));

        $read = $handler(new GetCartByIdQuery($cart->id()->toString()));

        self::assertSame($cart->id()->toString(), $read->id);
        self::assertSame(CartStatus::PENDING, $read->status);
        self::assertArrayHasKey($item->id()->toString(), $read->items);
        self::assertSame('Gafas', $read->items[$item->id()->toString()]->name);
    }

    /**
     * `ofId()` returns null for an unknown id; a `@var Cart` annotation hid it
     * and `CartRead::fromModel(null)` died with a TypeError - a 500.
     */
    public function test_an_unknown_cart_is_not_found(): void
    {
        $handler = new GetCartByIdQueryHandler($this->carts(null));
        $id = Uuid::uuid4()->toString();

        try {
            $handler(new GetCartByIdQuery($id));
            self::fail('expected an exception');
        } catch (CartNotFoundException $e) {
            self::assertStringContainsString($id, $e->getMessage());
        }
    }

    public function test_the_query_validates_its_identifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new GetCartByIdQuery('cart-1');
    }

    private function carts(?Cart $cart): CartRepository
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('ofId')->willReturn($cart);
        $carts->method('ofIdForUpdate')->willReturnCallback(
            static fn() => self::fail('a read must not lock the row'),
        );

        return $carts;
    }
}
