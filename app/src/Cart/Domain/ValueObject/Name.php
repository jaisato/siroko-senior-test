<?php

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\NameInvalidLengthException;

final class Name
{
    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 200;

    private string $value;

    /**
     * @param string $value
     * @throws NameInvalidLengthException
     */
    public function __construct(string $value)
    {
        $this->setName($value);
    }

    /**
     * @param string $name
     * @return void
     * @throws NameInvalidLengthException
     */
    private function setName(string $name): void
    {
        $this->checkIsValid($name);
        $this->value = $name;
    }

    /**
     * @param string $name
     * @return void
     * @throws NameInvalidLengthException
     */
    private function checkIsValid(string $name)
    {
        $length = strlen($name);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new NameInvalidLengthException("Invalid length for name: {$name}");
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

