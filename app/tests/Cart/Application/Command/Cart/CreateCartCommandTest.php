<?php

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\CreateCartCommand;
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

    /**
     * Sin productos, `$items` se quedaba sin inicializar y `getItems()` moría
     * con "must not be accessed before initialization" -un 500 por una lista
     * vacía-.
     */
    public function test_a_request_with_no_products_yields_no_items(): void
    {
        self::assertSame([], (new CreateCartCommand([]))->getItems());
    }
}
