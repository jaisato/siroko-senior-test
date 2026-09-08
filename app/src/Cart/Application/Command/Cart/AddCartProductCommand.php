<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Exception\InvalidCustomerIdException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CustomerId;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class AddCartProductCommand
{
    private readonly CartId $cartId;

    private readonly ProductId $productId;

    private readonly Quantity $quantity;

    private readonly ?CustomerId $customerId;

    /**
     * `$quantity` is how many units to add to the line; it defaults to one so
     * the bodyless PUT the API has always offered keeps its meaning.
     *
     * @throws InvalidIdentifierException
     * @throws InvalidQuantityException   when the quantity is not one a cart line accepts
     * @throws InvalidCustomerIdException
     */
    public function __construct(string $cartId, string $productId, int|string $quantity = CartItem::MIN_QUANTITY, ?string $customerId = null)
    {
        $this->cartId = CartId::fromString($cartId);
        $this->productId = ProductId::fromString($productId);
        $this->quantity = self::lineQuantity($quantity);
        $this->customerId = null === $customerId ? null : CustomerId::fromString($customerId);
    }

    public function cartId(): CartId
    {
        return $this->cartId;
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    /**
     * The units to reserve, typed for the stock movement, which takes nothing
     * below one. The constructor refuses smaller quantities, so the guard never
     * fires; it is what tells the type system so.
     *
     * @return positive-int
     */
    public function units(): int
    {
        $units = $this->quantity->asInt();

        if ($units < CartItem::MIN_QUANTITY) {
            throw new \LogicException('An add always asks for at least one unit; the constructor guarantees it.');
        }

        return $units;
    }

    /**
     * Validated here, before any lock is taken: asking for 0 units or for more
     * than a line holds is a malformed request, not a stock problem.
     *
     * @throws InvalidQuantityException
     */
    private static function lineQuantity(int|string $quantity): Quantity
    {
        $units = new Quantity($quantity);

        if ($units->asInt() < CartItem::MIN_QUANTITY) {
            throw new InvalidQuantityException('Quantity must be greater or equal to ' . CartItem::MIN_QUANTITY);
        }

        if ($units->asInt() > CartItem::MAX_QUANTITY) {
            throw new InvalidQuantityException('Quantity must be lower or equal to ' . CartItem::MAX_QUANTITY);
        }

        return $units;
    }

    /**
     * The authenticated caller, when the API authenticates; null otherwise.
     */
    public function customer(): ?CustomerId
    {
        return $this->customerId;
    }
}
