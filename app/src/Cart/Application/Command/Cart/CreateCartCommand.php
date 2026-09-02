<?php

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

class CreateCartCommand
{
    /**
     * Unidades mínimas por línea. No es la invariante de `Quantity` -que sólo
     * exige no ser negativa, porque el stock de un producto sí puede ser 0-,
     * sino la de un pedido: una línea de carrito pide al menos una unidad.
     */
    private const MIN_ORDERED_QUANTITY = 1;

    /**
     * @var array
     */
    private array $items = [];

    /**
     * @param array $products
     * @throws InvalidQuantityException
     */
    public function __construct(
        array $products,
    ) {
        $this->setItems($products);
    }

    /**
     * @param array $products
     * @return void
     * @throws InvalidQuantityException
     */
    private function setItems(array $products): void
    {
        foreach ($products as $product) {
            $quantity = (int) $product['quantity'];

            // `Quantity` acepta el 0 porque el stock de un producto puede ser
            // 0, pero pedir 0 unidades de un producto no es una línea de
            // carrito. Sin esta comprobación, `reserveStock()` lanzaba un
            // `UPDATE quantity = quantity - 0`, que no cambia ninguna fila;
            // MySQL informa de 0 filas afectadas, el handler lo lee como falta
            // de stock y un producto perfectamente disponible respondía 409.
            // Es una petición mal formada, así que 400 con el motivo.
            if ($quantity < self::MIN_ORDERED_QUANTITY) {
                throw new InvalidQuantityException(
                    'Quantity must be greater or equal to ' . self::MIN_ORDERED_QUANTITY
                );
            }

            $this->items[] = [
                'productId' => ProductId::fromString($product['productId']),
                'quantity' => new Quantity($quantity),
            ];
        }
    }

    /**
     * @return array
     */
    public function getItems(): array
    {
        return $this->items;
    }
}
