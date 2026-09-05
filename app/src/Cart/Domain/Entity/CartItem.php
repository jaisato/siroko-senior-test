<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Entity;

use Siroko\Cart\Domain\ValueObject\ItemId;

class CartItem
{
    private Cart $cart;

    public function __construct(
        private ItemId $id,
        private Product $product,
    ) {}

    public function id(): ItemId
    {
        return $this->id;
    }

    public function setCart(Cart $cart): void
    {
        $this->cart = $cart;
    }

    public function getCart(): Cart
    {
        return $this->cart;
    }

    public function belongsTo(Cart $cart): bool
    {
        return isset($this->cart) && $this->cart->id()->equals($cart->id());
    }

    public function setProduct(Product $product): void
    {
        $this->product = $product;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }
}
