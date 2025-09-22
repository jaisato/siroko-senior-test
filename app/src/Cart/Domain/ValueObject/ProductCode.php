<?php

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\InvalidProductCodeException;

final class ProductCode
{
    public const MIN_LENGTH = 1;
    public const MAX_LENGTH = 50;

    /**
     * @var string
     */
    private string $value;

    /**
     * @param int|string $code
     * @throws InvalidProductCodeException
     */
    public function __construct(int|string $code)
    {
        $this->setCode($code);
    }

    /**
     * @param int|string $code
     * @return void
     * @throws InvalidProductCodeException
     */
    private function setCode(int|string $code): void
    {
        $this->checkIsValidCode($code);
        $this->value = trim((string) $code);
    }

    /**
     * @param int|string $code
     * @return void
     * @throws InvalidProductCodeException
     */
    private function checkIsValidCode(int|string $code): void
    {
        $length = strlen(trim((string) $code));
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidProductCodeException("Product code is invalid: {$code}");
        }
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
