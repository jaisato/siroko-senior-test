<?php

namespace Siroko\Cart\Domain\Entity;

class Cart
{
    /**
     * @var array<int, Product> items in the cart
     */
    private array $items = [];

    public function addItem(Product $product): void
    {
        $this->items[] = $product;
    }

    public function getItems(): array
    {
        return $this->items;
    }
}
