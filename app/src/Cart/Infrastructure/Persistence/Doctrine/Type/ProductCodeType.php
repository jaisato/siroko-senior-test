<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Siroko\Cart\Domain\ValueObject\ProductCode;

final class ProductCodeType extends AbstractStringValueObjectType
{
    public const NAME = 'product_code';

    protected function voClass(): string
    {
        return ProductCode::class;
    }

    protected function typeName(): string
    {
        return self::NAME;
    }

    protected function defaultLength(): int
    {
        return ProductCode::MAX_LENGTH;
    }
}
