<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class QuantityType extends Type
{
    public const NAME = 'quantity';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'INT(11)';
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return 0;
        }

        $quantity = $value instanceof Quantity ? $value->asInt() : intval($value);

        return $quantity;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?Quantity
    {
        if ($value === null) {
            return new Quantity(0);
        }

        return new Quantity($value);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
