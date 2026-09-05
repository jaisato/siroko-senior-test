<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Dto;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Dto\Cart\CartItemRead;
use Siroko\Cart\Application\Dto\Cart\CartRead;
use Siroko\Cart\Application\Dto\PriceFormatter;
use Siroko\Cart\Application\Dto\Product\ProductRead;
use Siroko\Cart\Application\Dto\Product\ProductReadCollection;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class ReadModelsTest extends TestCase
{
    /**
     * `formatTo('es_ES')` did not exist in the pinned brick/money; every
     * product endpoint died on it.
     */
    public function test_prices_are_presented_for_spain(): void
    {
        self::assertSame("19,99\u{a0}€", PriceFormatter::format(Price::of('19.99', 'EUR')));
        self::assertSame("1.234,50\u{a0}€", PriceFormatter::format(Price::of('1234.50', 'EUR')));
        self::assertSame("5,00\u{a0}US$", PriceFormatter::format(Price::of('5', 'USD')));
    }

    public function test_product_read_flattens_a_product(): void
    {
        $product = self::product('Gafas', 'K3', '129.95', 12);

        $read = ProductRead::fromModel($product);

        self::assertSame($product->id()->toString(), $read->id);
        self::assertSame('Gafas', $read->name);
        self::assertSame('K3', $read->code);
        self::assertSame("129,95\u{a0}€", $read->price);
        self::assertSame(12, $read->quantity);
        self::assertSame(
            ['id' => $read->id, 'name' => 'Gafas', 'code' => 'K3', 'price' => "129,95\u{a0}€", 'quantity' => 12],
            json_decode(json_encode($read, \JSON_THROW_ON_ERROR), true),
        );
    }

    /** `public array $products;` uninitialised turned an empty page into `{}`. */
    public function test_an_empty_collection_is_an_empty_list_with_zero_pages(): void
    {
        $collection = ProductReadCollection::fromArray([], 1, 20, 0);

        self::assertSame(
            ['products' => [], 'page' => 1, 'pageSize' => 20, 'total' => 0, 'pages' => 0],
            json_decode(json_encode($collection, \JSON_THROW_ON_ERROR), true),
        );
        self::assertSame([], (new ProductReadCollection())->products);
    }

    public function test_a_collection_carries_its_page_and_the_size_of_the_catalogue(): void
    {
        $collection = ProductReadCollection::fromArray([self::product('Alpha'), self::product('Bravo')], 2, 2, 5);

        self::assertCount(2, $collection->products);
        self::assertSame('Alpha', $collection->products[0]->name);
        self::assertSame(2, $collection->page);
        self::assertSame(2, $collection->pageSize);
        self::assertSame(5, $collection->total);
        self::assertSame(3, $collection->pages, 'five products, two per page');
    }

    public function test_cart_item_read_flattens_the_line_and_its_product(): void
    {
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product('Gafas', 'K3', '129.95'));

        $read = CartItemRead::fromModel($item);

        self::assertSame($item->id()->toString(), $read->id);
        self::assertSame('Gafas', $read->name);
        self::assertSame('K3', $read->code);
        self::assertSame("129,95\u{a0}€", $read->price);
    }

    public function test_cart_read_keys_the_lines_by_item_id(): void
    {
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());
        $first = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product('First'));
        $second = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), self::product('Second'));
        $cart->addItem($first);
        $cart->addItem($second);
        $cart->pay();

        $read = CartRead::fromModel($cart);

        self::assertSame($cart->id()->toString(), $read->id);
        self::assertSame(CartStatus::PAID, $read->status);
        self::assertSame([$first->id()->toString(), $second->id()->toString()], array_keys($read->items));
        self::assertSame('Second', $read->items[$second->id()->toString()]->name);
    }

    public function test_an_empty_cart_reads_as_an_empty_item_list(): void
    {
        $read = CartRead::fromModel(new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending()));

        self::assertSame([], $read->items);
        self::assertSame(CartStatus::PENDING, $read->status);
    }

    private static function product(string $name = 'A product', string $code = 'SKU', string $amount = '10.00', int $stock = 1): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString($code),
            Name::fromString($name),
            Price::of($amount, 'EUR'),
            new Quantity($stock),
        );
    }
}
