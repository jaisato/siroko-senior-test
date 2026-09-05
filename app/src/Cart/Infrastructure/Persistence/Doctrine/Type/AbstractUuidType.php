<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use Ramsey\Uuid\Exception\InvalidUuidStringException;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\Identifier;

/**
 * Stores a UUID identifier as 16 raw bytes.
 *
 * The column declaration comes from the platform - BINARY(16) on MySQL, BLOB on
 * SQLite - instead of a hard-coded `BINARY(16)`, so the same mapping creates a
 * usable schema on the SQLite database the test suite runs against locally.
 *
 * `getName()` stays because DBAL 3 declares it abstract; `requiresSQLCommentHint()`
 * is gone (deprecated, and DBAL 4 drops the whole comment mechanism).
 */
abstract class AbstractUuidType extends Type
{
    /**
     * @return class-string<Identifier>
     */
    abstract protected function identifierClass(): string;

    /**
     * @param mixed[] $column
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getBinaryTypeDeclarationSQL(['length' => 16, 'fixed' => true]);
    }

    public function getBindingType(): int
    {
        return ParameterType::BINARY;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Identifier) {
            $value = $value->toString();
        }

        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'string', Identifier::class]);
        }

        try {
            return Uuid::fromString($value)->getBytes();
        } catch (InvalidUuidStringException $e) {
            throw ConversionException::conversionFailed($value, $this->getName(), $e);
        }
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Identifier
    {
        if (null === $value) {
            return null;
        }

        $class = $this->identifierClass();

        if ($value instanceof $class) {
            return $value;
        }

        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'string']);
        }

        $uuid = 16 === \strlen($value) ? Uuid::fromBytes($value)->toString() : $value;

        return $class::fromString($uuid);
    }
}
