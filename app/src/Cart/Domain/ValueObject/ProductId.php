<?php

namespace Siroko\Cart\Domain\ValueObject;

final class ProductId
{
    private function __construct(
        private string $value
    ) {
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
