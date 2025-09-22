<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Siroko\Cart\Domain\ValueObject\CartStatus;

final class CartStatusType extends Type
{
    public const NAME = 'cart_status';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'INT(11)';
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return CartStatus::PENDING;
        }

        $status = $value instanceof CartStatus ? $value->toInt() : intval($value);

        return $status;
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?CartStatus
    {
        if ($value === null) {
            return new CartStatus(CartStatus::PENDING);
        }

        return new CartStatus($value);
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
