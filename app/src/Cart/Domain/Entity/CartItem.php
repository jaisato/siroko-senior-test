<?php

namespace Siroko\Cart\Domain\Entity;

use Siroko\Cart\Domain\ValueObject\ItemId;

class CartItem
{
    /**
     * @var ItemId
     */
    private ItemId $id;

    /**
     * @var Product
     */
    private Product $product;

    /**
     * @var Cart
     */
    private Cart $cart;

    /**
     * @param ItemId $id
     * @param Product $product
     */
    public function __construct(ItemId $id, Product $product)
    {
        $this->id = $id;
        $this->product = $product;
    }

    /**
     * @return ItemId
     */
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

    public function setProduct(Product $product): void
    {
        $this->product = $product;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }
}
