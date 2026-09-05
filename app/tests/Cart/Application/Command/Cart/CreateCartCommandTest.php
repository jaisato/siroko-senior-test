<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\CreateCartCommand;
use Siroko\Cart\Domain\Exception\InvalidCartLineException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;

/**
 * Una línea de carrito pide al menos una unidad.
 *
 * `Quantity` acepta el 0 porque el stock de un producto puede ser 0, y el
 * controlador POST es propio -no pasa por la validación del esquema OpenAPI,
 * que sí declara `minimum: 1`-, así que un `quantity: 0` llegaba entero hasta
 * la reserva de stock. Allí se convertía en un `UPDATE quantity = quantity - 0`
 * que no toca ninguna fila; MySQL informa de 0 filas afectadas, el handler lo
 * lee como falta de stock y un producto perfectamente disponible respondía 409.
 * Un 409 "sin stock" sobre un producto con stock no hay forma de que el cliente
 * lo entienda ni lo corrija: es una petición mal formada y se responde 400.
 */
final class CreateCartCommandTest extends TestCase
{
    public function test_a_line_asking_for_no_units_is_rejected(): void
    {
        $this->expectException(InvalidQuantityException::class);

        new CreateCartCommand([
            ['productId' => Uuid::uuid4()->toString(), 'quantity' => 0],
        ]);
    }

    public function test_a_line_asking_for_a_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidQuantityException::class);

        new CreateCartCommand([
            ['productId' => Uuid::uuid4()->toString(), 'quantity' => -3],
        ]);
    }

    /** Una línea válida en la misma petición no salva a la que no lo es. */
    public function test_one_bad_line_rejects_the_whole_request(): void
    {
        $this->expectException(InvalidQuantityException::class);

        new CreateCartCommand([
            ['productId' => Uuid::uuid4()->toString(), 'quantity' => 2],
            ['productId' => Uuid::uuid4()->toString(), 'quantity' => 0],
        ]);
    }

    public function test_a_line_asking_for_one_unit_is_accepted(): void
    {
        $productId = Uuid::uuid4()->toString();

        $command = new CreateCartCommand([
            ['productId' => $productId, 'quantity' => 1],
        ]);

        $items = $command->getItems();

        self::assertCount(1, $items);
        self::assertSame($productId, $items[0]['productId']->toString());
        self::assertSame(1, $items[0]['quantity']->asInt());
    }

    public function test_quantities_may_arrive_as_integer_strings(): void
    {
        $command = new CreateCartCommand([
            ['productId' => Uuid::uuid4()->toString(), 'quantity' => '4'],
        ]);

        self::assertSame(4, $command->getItems()[0]['quantity']->asInt());
    }

    /**
     * Sin productos, `$items` se quedaba sin inicializar y `getItems()` moría
     * con "must not be accessed before initialization" -un 500 por una lista
     * vacía-.
     */
    public function test_a_request_with_no_products_yields_no_items(): void
    {
        self::assertSame([], (new CreateCartCommand([]))->getItems());
    }

    /**
     * Every unit is a row: the caps keep one request within what a person buys
     * and what the database should be asked to do at once.
     */
    public function test_a_line_above_the_per_line_cap_is_rejected(): void
    {
        self::assertSame(
            CreateCartCommand::MAX_ORDERED_QUANTITY,
            (new CreateCartCommand([['productId' => Uuid::uuid4()->toString(), 'quantity' => CreateCartCommand::MAX_ORDERED_QUANTITY]]))->getItems()[0]['quantity']->asInt(),
        );

        $this->expectException(InvalidQuantityException::class);
        $this->expectExceptionMessage('lower or equal to ' . CreateCartCommand::MAX_ORDERED_QUANTITY);

        new CreateCartCommand([['productId' => Uuid::uuid4()->toString(), 'quantity' => CreateCartCommand::MAX_ORDERED_QUANTITY + 1]]);
    }

    public function test_more_lines_than_the_cap_are_rejected(): void
    {
        $lines = [];
        for ($i = 0; $i <= CreateCartCommand::MAX_LINES; ++$i) {
            $lines[] = ['productId' => Uuid::uuid4()->toString(), 'quantity' => 1];
        }

        $this->expectException(InvalidCartLineException::class);
        $this->expectExceptionMessage('at most ' . CreateCartCommand::MAX_LINES);

        new CreateCartCommand($lines);
    }

    /**
     * Reading `$product['quantity']` off an unchecked array raised a PHP error
     * - a 500 - for a line that was missing a key or was not an object at all.
     *
     * @param array<mixed> $products
     */
    #[DataProvider('malformedLines')]
    public function test_a_malformed_line_is_rejected_naming_the_line(array $products, string $message): void
    {
        $this->expectException(InvalidCartLineException::class);
        $this->expectExceptionMessage($message);

        new CreateCartCommand($products);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function malformedLines(): iterable
    {
        yield 'a scalar line' => [['abc'], 'Line 0 must be an object'];
        yield 'a null line' => [[null], 'Line 0 must be an object'];
        yield 'missing productId' => [[['quantity' => 1]], 'Line 0 is missing the field "productId"'];
        yield 'missing quantity' => [[['productId' => Uuid::uuid4()->toString()]], 'Line 0 is missing the field "quantity"'];
        yield 'null quantity' => [[['productId' => Uuid::uuid4()->toString(), 'quantity' => null]], 'Line 0 is missing the field "quantity"'];
        yield 'productId not a string' => [[['productId' => 42, 'quantity' => 1]], 'Line 0: the field "productId" must be a string'];
        yield 'quantity is an array' => [[['productId' => Uuid::uuid4()->toString(), 'quantity' => [1]]], 'Line 0: the field "quantity" must be an integer'];
        yield 'quantity is a float' => [[['productId' => Uuid::uuid4()->toString(), 'quantity' => 1.5]], 'Line 0: the field "quantity" must be an integer'];
        yield 'second line broken' => [[['productId' => Uuid::uuid4()->toString(), 'quantity' => 1], 'x'], 'Line 1 must be an object'];
    }

    public function test_a_quantity_string_that_is_not_an_integer_is_rejected(): void
    {
        $this->expectException(InvalidQuantityException::class);

        new CreateCartCommand([['productId' => Uuid::uuid4()->toString(), 'quantity' => 'two']]);
    }

    public function test_a_malformed_product_id_is_rejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new CreateCartCommand([['productId' => 'product-1', 'quantity' => 1]]);
    }
}
