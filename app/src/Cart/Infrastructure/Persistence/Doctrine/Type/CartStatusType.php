<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Siroko\Cart\Domain\ValueObject\CartStatus;

/**
 * Stores the cart status as its integer code.
 *
 * The declaration comes from the platform rather than a literal `INT(11)`, and
 * the conversion methods say what they return: the previous `?string` signature
 * handed an int back, and a null turned into "pending" in silence instead of
 * being the mapping error it is on a NOT NULL column.
 */
final class CartStatusType extends Type
{
    public const NAME = 'cart_status';

    /**
     * @param mixed[] $column
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function getBindingType(): int
    {
        return ParameterType::INTEGER;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof CartStatus) {
            return $value->toInt();
        }

        if (\is_int($value)) {
            return $value;
        }

        throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', 'int', CartStatus::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CartStatus
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof CartStatus) {
            return $value;
        }

        if (!\is_int($value) && !(\is_string($value) && 1 === preg_match('/^\d+$/', $value))) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', 'int']);
        }

        return new CartStatus((int) $value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
