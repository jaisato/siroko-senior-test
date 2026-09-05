<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Exception\InvalidCartLineException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * @phpstan-type CartLine array{productId: ProductId, quantity: Quantity}
 */
final class CreateCartCommand
{
    /**
     * Unidades mínimas por línea. No es la invariante de `Quantity` -que sólo
     * exige no ser negativa, porque el stock de un producto sí puede ser 0-,
     * sino la de un pedido: una línea de carrito pide al menos una unidad.
     */
    public const MIN_ORDERED_QUANTITY = 1;

    /**
     * Every unit becomes a `cart_item` row, so an unbounded quantity is an
     * unbounded number of INSERTs from one request. The caps keep a single
     * cart within what a person buys and a request within what the database
     * should be asked to do at once.
     */
    public const MAX_ORDERED_QUANTITY = 100;

    public const MAX_LINES = 50;

    /**
     * @var list<CartLine>
     */
    private array $items = [];

    /**
     * @param array<mixed> $products the decoded "products" list of the request
     *
     * @throws InvalidCartLineException   when a line does not have the expected shape
     * @throws InvalidQuantityException   when a quantity is not one a cart line accepts
     * @throws InvalidIdentifierException when a product id is not a UUID
     */
    public function __construct(array $products)
    {
        if (\count($products) > self::MAX_LINES) {
            throw InvalidCartLineException::tooManyLines(self::MAX_LINES);
        }

        $position = 0;

        foreach ($products as $product) {
            $this->items[] = self::line($position++, $product);
        }
    }

    /**
     * The command owns the shape of its input. Reading `$product['quantity']`
     * off an unchecked array raised a PHP error - a 500 - for a line that was
     * missing a key or was not an object at all; those are the caller's
     * mistakes and get a 400 that names the line.
     *
     * @return CartLine
     */
    private static function line(int $position, mixed $product): array
    {
        if (!\is_array($product)) {
            throw InvalidCartLineException::notAnObject($position);
        }

        foreach (['productId', 'quantity'] as $field) {
            if (!\array_key_exists($field, $product) || null === $product[$field] || '' === $product[$field]) {
                throw InvalidCartLineException::missingField($position, $field);
            }
        }

        if (!\is_string($product['productId'])) {
            throw InvalidCartLineException::wrongType($position, 'productId', 'a string');
        }

        if (!\is_int($product['quantity']) && !\is_string($product['quantity'])) {
            throw InvalidCartLineException::wrongType($position, 'quantity', 'an integer');
        }

        // `Quantity` acepta el 0 porque el stock de un producto puede ser
        // 0, pero pedir 0 unidades de un producto no es una línea de
        // carrito. Sin esta comprobación, `reserveStock()` lanzaba un
        // `UPDATE quantity = quantity - 0`, que no cambia ninguna fila;
        // MySQL informa de 0 filas afectadas, el handler lo lee como falta
        // de stock y un producto perfectamente disponible respondía 409.
        // Es una petición mal formada, así que 400 con el motivo.
        $quantity = new Quantity($product['quantity']);

        if ($quantity->asInt() < self::MIN_ORDERED_QUANTITY) {
            throw new InvalidQuantityException('Quantity must be greater or equal to ' . self::MIN_ORDERED_QUANTITY);
        }

        if ($quantity->asInt() > self::MAX_ORDERED_QUANTITY) {
            throw new InvalidQuantityException('Quantity must be lower or equal to ' . self::MAX_ORDERED_QUANTITY);
        }

        return [
            'productId' => ProductId::fromString($product['productId']),
            'quantity' => $quantity,
        ];
    }

    /**
     * @return list<CartLine>
     */
    public function getItems(): array
    {
        return $this->items;
    }
}
