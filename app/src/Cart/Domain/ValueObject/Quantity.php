<?php

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\InvalidQuantityException;

final class Quantity
{
    public const MIN_QUANTITY = 0;

    /**
     * @var int
     */
    private int $quantity;

    /**
     * @param string|int $quantity
     * @throws InvalidQuantityException
     */
    public function __construct(string|int $quantity)
    {
        if (is_string($quantity)) {
            $quantity = intval($quantity);
        }

        $this->setQuantity($quantity);
    }

    /**
     * @param int $quantity
     * @return void
     * @throws InvalidQuantityException
     */
    private function setQuantity(int $quantity): void
    {
        $this->isValidQuantity($quantity);
        $this->quantity = $quantity;
    }

    /**
     * @param int $quantity
     * @return void
     * @throws InvalidQuantityException
     */
    private function isValidQuantity(int $quantity): void
    {
        if ($quantity < self::MIN_QUANTITY) {
            throw new InvalidQuantityException('Quantity must be greater or equal to ' . self::MIN_QUANTITY);
        }
    }

    /**
     * @return int
     */
    public function asInt(): int
    {
        return $this->quantity;
    }

    /**
     * @return string
     */
    public function asString(): string
    {
        return (string) $this->quantity;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->asString();
    }

    /**
     * @param Quantity $quantity
     * @return Quantity
     * @throws InvalidQuantityException
     */
    public static function decrement(Quantity $quantity): Quantity
    {
        $newQuantity = $quantity->asInt() - 1;
        return new self($newQuantity);
    }

    /**
     * @param Quantity $quantity
     * @return Quantity
     * @throws InvalidQuantityException
     */
    public static function increment(Quantity $quantity): Quantity
    {
        $newQuantity = $quantity->asInt() + 1;
        return new self($newQuantity);
    }
}
