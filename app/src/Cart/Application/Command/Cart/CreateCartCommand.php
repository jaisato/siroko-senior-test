<?php

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

class CreateCartCommand
{
    /**
     * @var array
     */
    private array $items;

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
