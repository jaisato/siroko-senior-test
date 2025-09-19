<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\CartId;

final class CartIdType extends Type
{
    public const NAME = 'cart_id';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'BINARY(16)';
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $uuid = $value instanceof CartId ? $value->toString() : (string) $value;

        return Uuid::fromString($uuid)->getBytes(); // 16 bytes
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?CartId
    {
        if ($value === null) {
            return null;
        }

        $uuid = \strlen($value) === 16 ? Uuid::fromBytes($value)->toString() : (string) $value;
        return CartId::fromString($uuid);
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
