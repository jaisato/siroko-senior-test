<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * Stores a quantity as an integer; see CartStatusType for the reasoning
 * behind the platform-derived declaration and the honest signatures.
 */
final class QuantityType extends Type
{
    public const NAME = 'quantity';

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

        if ($value instanceof Quantity) {
            return $value->asInt();
        }

        if (\is_int($value)) {
            return $value;
        }

        throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', 'int', Quantity::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Quantity
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Quantity) {
            return $value;
        }

        if (!\is_int($value) && !(\is_string($value) && 1 === preg_match('/^-?\d+$/', $value))) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', 'int']);
        }

        return new Quantity((int) $value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
