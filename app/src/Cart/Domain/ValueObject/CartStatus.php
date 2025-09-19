<?php

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\InvalidCartStatusException;

final class CartStatus
{
    public const PENDING = 1;

    public const PAID = 2;

    public const DELIVERED = 3;

    public const CANCELED = 4;

    /**
     * @var int
     */
    private int $value;

    /**
     * @param int $status
     * @throws InvalidCartStatusException
     */
    public function __construct(int $status)
    {
        $this->setStatus($status);
    }

    /**
     * @param int $status
     * @return void
     * @throws InvalidCartStatusException
     */
    private function setStatus(int $status): void
    {
        $this->checkIsValidStatus($status);
        $this->value = $status;
    }

    /**
     * @param int $status
     * @return void
     * @throws InvalidCartStatusException
     */
    private function checkIsValidStatus(int $status): void
    {
        if (!in_array($status, [self::PENDING, self::PAID, self::DELIVERED, self::CANCELED], true)) {
            throw new InvalidCartStatusException("Cart status is invalid: {$status}");
        }
    }

    /**
     * @return int
     */
    public function toInt(): int
    {
        return $this->value;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
