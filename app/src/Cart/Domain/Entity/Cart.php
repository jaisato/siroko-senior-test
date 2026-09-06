<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Entity;

use Brick\Money\Currency;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\EmptyCartException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Exception\PriceIsNotSameCurrencyException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\CustomerId;
use Siroko\Cart\Domain\Exception\CartIsFullException;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

class Cart
{
    /**
     * How many distinct products one cart holds.
     *
     * Price::MAX_AMOUNT is computed from this and CartItem::MAX_QUANTITY, so
     * the two have to be enforced in the same place they are assumed: a cart
     * that grew past the cap would build a total wider than
     * orders.total_amount and fail at checkout, having been accepted all the
     * way there.
     */
    public const MAX_LINES = 50;

    /**
     * @var Collection<int, CartItem>
     */
    private Collection $items;

    private \DateTimeImmutable $createdAt;

    /**
     * When the reservation this cart holds lapses. Set while the cart is
     * pending; a paid or canceled cart reserves nothing, so it has none.
     */
    private ?\DateTimeImmutable $expiresAt;

    /**
     * The customer the cart belongs to; null for a cart opened while the API
     * ran without authentication, which belongs to nobody in particular.
     */
    private ?CustomerId $customerId;

    /**
     * `$createdAt` defaults to the wall clock for the benefit of tests and
     * fixtures; the application opens carts through open(), which takes the
     * instant from the injected clock.
     */
    public function __construct(
        private CartId $id,
        private CartStatus $status,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?CustomerId $customerId = null,
    ) {
        $this->items = new ArrayCollection();
        $this->createdAt = $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->expiresAt = $expiresAt;
        $this->customerId = $customerId;
    }

    /**
     * A new, pending cart whose reservation lasts `$reservationTtl` from now,
     * owned by `$customer` when the caller is known.
     *
     * Units are taken off the shelf the moment a line is added, so a cart that
     * is never checked out would hold them forever. The deadline is what lets
     * the release sweep (cart:release-expired) give them back.
     */
    public static function open(CartId $id, \DateTimeImmutable $now, \DateInterval $reservationTtl, ?CustomerId $customer = null): self
    {
        return new self($id, CartStatus::pending(), $now, $now->add($reservationTtl), $customer);
    }

    public function id(): CartId
    {
        return $this->id;
    }

    public function customerId(): ?CustomerId
    {
        return $this->customerId;
    }

    /**
     * Whether `$caller` may see and change this cart.
     *
     * With authentication off there is no caller, and every cart is open. With
     * it on, a cart that has an owner is that owner's alone; a cart without one
     * predates authentication and stays open to any authenticated caller, so
     * turning authentication on does not strand the carts that already exist.
     */
    public function isAccessibleBy(?CustomerId $caller): bool
    {
        return null === $caller || null === $this->customerId || $this->customerId->equals($caller);
    }

    /**
     * Someone else's cart does not exist as far as the caller is concerned:
     * answering 403 would confirm the identifier belongs to somebody.
     *
     * @throws CartNotFoundException
     */
    public function ensureAccessibleBy(?CustomerId $caller): void
    {
        if (!$this->isAccessibleBy($caller)) {
            throw CartNotFoundException::withId($this->id);
        }
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Whether the reservation has lapsed: a pending cart past its deadline.
     * Checked again under the row lock by the sweep, so a checkout that wins
     * the race is respected.
     */
    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->status->isPending() && null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    public function status(): CartStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    /**
     * @return Collection<int, CartItem>
     */
    public function items(): Collection
    {
        return $this->items;
    }

    public function itemOfId(ItemId $id): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->id()->equals($id)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The line holding a product, if the cart has one. A cart holds at most
     * one line per product; that is what lets "add this product" mean "one
     * more unit" rather than "one more row".
     */
    public function itemForProduct(ProductId $productId): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->getProduct()->id()->equals($productId)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Puts units of a product in the cart: on the line that already holds the
     * product, or on a new one identified by `$newLineId`.
     *
     * Only a pending cart changes. Once paid, its lines are what the customer
     * bought; adding to it reserved stock that nothing ever released, because
     * the removal path rightly refuses to give back units that were sold.
     *
     * The caller has reserved the units already; the cart only records where
     * they went.
     *
     * @throws InvalidCartStatusException
     * @throws InvalidQuantityException        when the line would exceed CartItem::MAX_QUANTITY
     * @throws PriceIsNotSameCurrencyException when the product is priced in another currency than the cart
     */
    public function addProduct(ItemId $newLineId, Product $product, Quantity $units): CartItem
    {
        $this->ensurePending();
        $this->ensureSameCurrency($product);

        $line = $this->itemForProduct($product->id());

        if (null !== $line) {
            $line->increase($units);

            return $line;
        }

        $this->ensureRoomForAnotherLine();

        $line = new CartItem($newLineId, $product, $units);
        $this->items->add($line);
        $line->setCart($this);

        return $line;
    }

    /**
     * Attaches a ready-made line. Adding the same instance twice is a no-op; a
     * second line for a product the cart already holds folds into the existing
     * one, so the "one line per product" rule holds however the line arrives.
     *
     * @throws InvalidCartStatusException
     * @throws InvalidQuantityException        when the merged line would exceed CartItem::MAX_QUANTITY
     * @throws PriceIsNotSameCurrencyException when the product is priced in another currency than the cart
     */
    public function addItem(CartItem $item): void
    {
        $this->ensurePending();

        if ($this->items->contains($item)) {
            return;
        }

        $this->ensureSameCurrency($item->getProduct());

        $line = $this->itemForProduct($item->getProduct()->id());

        if (null !== $line) {
            $line->increase($item->quantity());

            return;
        }

        $this->ensureRoomForAnotherLine();

        $this->items->add($item);
        $item->setCart($this);
    }

    /**
     * The cap was checked only where a whole cart arrives at once, so adding
     * products one request at a time walked straight past it.
     *
     * @throws CartIsFullException
     */
    private function ensureRoomForAnotherLine(): void
    {
        if ($this->items->count() >= self::MAX_LINES) {
            throw CartIsFullException::atLineLimit(self::MAX_LINES);
        }
    }

    /**
     * The currency every line is priced in; null while the cart is empty. A
     * cart never mixes currencies (see ensureSameCurrency()), so the first line
     * speaks for all of them.
     */
    public function currency(): ?Currency
    {
        $first = $this->items->first();

        return $first instanceof CartItem ? $first->unitPrice()->currency() : null;
    }

    /**
     * Units across every line.
     *
     * @return int<0, max>
     */
    public function itemCount(): int
    {
        $count = 0;

        foreach ($this->items as $item) {
            $count += $item->quantity()->asInt();
        }

        return max(0, $count);
    }

    /**
     * Sum of the line totals; null while the cart is empty, since an amount
     * without a currency is not a price.
     *
     * @throws PriceIsNotSameCurrencyException if the lines somehow ended up in two currencies
     */
    public function subtotal(): ?Price
    {
        $subtotal = null;

        foreach ($this->items as $item) {
            $subtotal = null === $subtotal ? $item->total() : $subtotal->add($item->total());
        }

        return $subtotal;
    }

    /**
     * What the customer pays. Equal to the subtotal until the day taxes or
     * discounts exist; kept apart so that day changes one method, not the API.
     *
     * @throws PriceIsNotSameCurrencyException
     */
    public function total(): ?Price
    {
        return $this->subtotal();
    }

    /**
     * The inverse side is enough: the association is mapped with
     * orphan-removal, so dropping the item from this collection is what
     * deletes it.
     *
     * @throws InvalidCartStatusException
     */
    public function removeItem(CartItem $item): void
    {
        $this->ensurePending();

        $this->items->removeElement($item);
    }

    /**
     * Checking out a cart twice is a conflict, not a fresh payment; checking
     * out an empty one is a payment for nothing, and refused as well.
     *
     * @throws InvalidCartStatusException
     * @throws EmptyCartException
     */
    public function pay(): void
    {
        $this->ensurePending();

        if ($this->items->isEmpty()) {
            throw EmptyCartException::cannotBePaid();
        }

        // The prices stop being an offer here. A line points at a product and a
        // product changes, so without this copy a paid cart's totals moved
        // with the catalogue - and a reprice into another currency made
        // subtotal() throw, which is a 409 on reading a purchase that is done.
        foreach ($this->items as $item) {
            $item->capturePrice();
        }

        $this->status = CartStatus::paid();
        // Paid units are sold, not reserved: there is nothing left to expire.
        $this->expiresAt = null;
    }

    /**
     * Only what was paid for gets delivered.
     *
     * @throws InvalidCartStatusException
     */
    public function deliver(): void
    {
        if (!$this->status->isPaid()) {
            throw new InvalidCartStatusException('Cart is not paid');
        }

        $this->status = CartStatus::delivered();
    }

    /**
     * A pending cart is abandoned, a paid one is called off; either way the
     * units its lines hold are the caller's to give back to stock. A delivered
     * cart is done with, and a canceled one already is.
     *
     * @throws InvalidCartStatusException
     */
    public function cancel(): void
    {
        if (!$this->status->isPending() && !$this->status->isPaid()) {
            throw new InvalidCartStatusException('Cart is neither pending nor paid');
        }

        $this->status = CartStatus::canceled();
        $this->expiresAt = null;
    }

    /**
     * @throws InvalidCartStatusException
     */
    public function ensurePending(): void
    {
        if (!$this->status->isPending()) {
            throw new InvalidCartStatusException('Cart is not pending');
        }
    }

    /**
     * A cart is paid in one currency. Lines in two currencies have no total,
     * so the mix is refused when the line arrives rather than discovered when
     * the cart is read or checked out.
     *
     * @throws PriceIsNotSameCurrencyException
     */
    private function ensureSameCurrency(Product $product): void
    {
        $currency = $this->currency();

        if (null !== $currency && $currency->getCurrencyCode() !== $product->price()->currency()->getCurrencyCode()) {
            throw new PriceIsNotSameCurrencyException(\sprintf('The cart is priced in %s; a product priced in %s cannot be added to it.', $currency->getCurrencyCode(), $product->price()->currency()->getCurrencyCode()));
        }
    }
}
