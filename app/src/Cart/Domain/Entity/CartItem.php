<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Entity;

use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * One line of a cart: a product and how many units of it are reserved.
 *
 * A line used to stand for exactly one unit, so three units of a product were
 * three rows with three ids that a client had to remove one by one, and a cart
 * of a hundred units was a hundred INSERTs. The quantity is now a column; the
 * pair (cart, product) names a line, and adding a product a cart already holds
 * grows that line instead of opening another.
 */
class CartItem
{
    /**
     * Fewest units a line can hold: a line for nothing is not a line, it is a
     * removal, and the handlers treat a requested quantity of 0 as one.
     */
    public const MIN_QUANTITY = 1;

    /**
     * Most units a single line accepts. It keeps one cart within what a person
     * buys and one request within what the stock movement should be asked to
     * reserve at once.
     */
    public const MAX_QUANTITY = 100;

    private Cart $cart;

    private Quantity $quantity;

    /**
     * @throws InvalidQuantityException when the quantity is not one a line accepts
     */
    public function __construct(
        private ItemId $id,
        private Product $product,
        ?Quantity $quantity = null,
    ) {
        $this->quantity = self::lineQuantity($quantity ?? new Quantity(self::MIN_QUANTITY));
    }

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

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    /**
     * The units this line reserves, typed for the stock movements, which take
     * nothing below one. The constructor and both mutators hold the line at
     * MIN_QUANTITY or above, so the guard never fires; it is what tells the
     * type system so.
     *
     * @return positive-int
     */
    public function units(): int
    {
        $units = $this->quantity->asInt();

        if ($units < self::MIN_QUANTITY) {
            throw new \LogicException('A cart line always holds at least one unit.');
        }

        return $units;
    }

    /**
     * Adds units to the line. The caller has reserved them already; this only
     * records that they belong here.
     *
     * @throws InvalidQuantityException when the line would exceed MAX_QUANTITY
     */
    public function increase(Quantity $units): void
    {
        $this->quantity = self::lineQuantity(new Quantity($this->quantity->asInt() + $units->asInt()));
    }

    /**
     * Sets the line to an exact number of units. Returns the difference the
     * caller has to settle with the stock: positive means units to reserve,
     * negative means units to give back.
     *
     * @throws InvalidQuantityException when the quantity is not one a line accepts
     */
    public function changeQuantity(Quantity $quantity): int
    {
        $delta = $quantity->asInt() - $this->quantity->asInt();

        $this->quantity = self::lineQuantity($quantity);

        return $delta;
    }

    /**
     * @throws InvalidQuantityException
     */
    private static function lineQuantity(Quantity $quantity): Quantity
    {
        if ($quantity->asInt() < self::MIN_QUANTITY) {
            throw new InvalidQuantityException('Quantity must be greater or equal to ' . self::MIN_QUANTITY);
        }

        if ($quantity->asInt() > self::MAX_QUANTITY) {
            throw new InvalidQuantityException('Quantity must be lower or equal to ' . self::MAX_QUANTITY);
        }

        return $quantity;
    }
}
