<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Siroko\Cart\Domain\ValueObject\Name;

final class ProductNameType extends AbstractStringValueObjectType
{
    public const NAME = 'product_name';

    protected function voClass(): string
    {
        return Name::class;
    }

    protected function typeName(): string
    {
        return self::NAME;
    }

    protected function defaultLength(): int
    {
        return Name::MAX_LENGTH;
    }
}
