<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\ItemId;

final class ItemIdType extends Type
{
    public const NAME = 'item_id';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'BINARY(16)';
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $uuid = $value instanceof ItemId ? $value->toString() : (string) $value;

        return Uuid::fromString($uuid)->getBytes(); // 16 bytes
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?ItemId
    {
        if ($value === null) {
            return null;
        }

        $uuid = \strlen($value) === 16 ? Uuid::fromBytes($value)->toString() : (string) $value;
        return ItemId::fromString($uuid);
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
