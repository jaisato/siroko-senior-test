<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Product;

use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Exception\InvalidStockAdjustmentException;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * Changes the available stock of a product: to an exact figure (`quantity`,
 * a recount) or by a difference (`delta`, a delivery in or a write-off).
 *
 * "Available" is what the column holds: units already reserved by carts were
 * subtracted when they were reserved, so an absolute figure never touches
 * them and a negative delta cannot take the available count below zero.
 */
final class AdjustProductStockCommand
{
    private readonly ProductId $id;

    private readonly ?Quantity $quantity;

    private readonly ?int $delta;

    /**
     * @throws InvalidIdentifierException
     * @throws InvalidStockAdjustmentException when neither or both of quantity and delta are given, or the delta is 0 or out of range
     * @throws InvalidQuantityException        when the absolute quantity is not one a product accepts
     */
    public function __construct(string $id, int|string|null $quantity = null, int|string|null $delta = null)
    {
        $this->id = ProductId::fromString($id);

        if ((null === $quantity) === (null === $delta)) {
            throw InvalidStockAdjustmentException::exactlyOneOfQuantityOrDelta();
        }

        $this->quantity = null === $quantity ? null : new Quantity($quantity);
        $this->delta = null === $delta ? null : self::parseDelta($delta);
    }

    public function id(): ProductId
    {
        return $this->id;
    }

    public function quantity(): ?Quantity
    {
        return $this->quantity;
    }

    public function delta(): ?int
    {
        return $this->delta;
    }

    /**
     * @throws InvalidStockAdjustmentException
     */
    private static function parseDelta(int|string $delta): int
    {
        if (\is_string($delta)) {
            if (1 !== preg_match('/^-?\d{1,10}$/', $delta)) {
                throw InvalidStockAdjustmentException::deltaOutOfRange(Quantity::MAX_QUANTITY);
            }

            $delta = (int) $delta;
        }

        if (0 === $delta) {
            throw InvalidStockAdjustmentException::deltaIsZero();
        }

        if ($delta < -Quantity::MAX_QUANTITY || $delta > Quantity::MAX_QUANTITY) {
            throw InvalidStockAdjustmentException::deltaOutOfRange(Quantity::MAX_QUANTITY);
        }

        return $delta;
    }
}
