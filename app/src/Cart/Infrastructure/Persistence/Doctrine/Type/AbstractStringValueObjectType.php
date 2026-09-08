<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Siroko\Cart\Domain\ValueObject\StringValueObject;

/**
 * Stores a string-backed value object in a VARCHAR column.
 *
 * Hydration goes through `fromPersistence()`: re-running the write-path rules on
 * every row meant that tightening a rule turned existing rows unreadable.
 *
 * `getName()` stays because DBAL 3 declares it abstract; `requiresSQLCommentHint()`
 * is gone (deprecated, and DBAL 4 drops the whole comment mechanism).
 */
abstract class AbstractStringValueObjectType extends Type
{
    /**
     * @return class-string<StringValueObject>
     */
    abstract protected function voClass(): string;

    abstract protected function typeName(): string;

    protected function defaultLength(): int
    {
        return 255;
    }

    public function getName(): string
    {
        return $this->typeName();
    }

    /**
     * @param mixed[] $column
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] ??= $this->defaultLength();
        $column['fixed'] ??= false;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (\is_string($value)) {
            return $value;
        }

        $class = $this->voClass();

        if (!$value instanceof $class) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'string', $class]);
        }

        return $value->toString();
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?StringValueObject
    {
        if (null === $value) {
            return null;
        }

        $class = $this->voClass();

        if ($value instanceof $class) {
            return $value;
        }

        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'string']);
        }

        return $class::fromPersistence($value);
    }
}
