<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

abstract class AbstractStringValueObjectType extends Type
{
    abstract protected function voClass(): string;     // ex: ProductName::class

    abstract protected function typeName(): string;    // alias Doctrine, ex: 'product_name'

    protected function defaultLength(): int
    {
        return 255;
    }

    public function getName(): string
    {
        return $this->typeName();
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        // Mantiene el alias del tipo en el esquema
        return true;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] = $column['length'] ?? $this->defaultLength();
        $column['fixed'] = $column['fixed'] ?? false;

        return $platform->getVarcharTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $vo = $value;
        $class = $this->voClass();

        if (\is_string($value)) {
            return $value;
        }

        if (!$vo instanceof $class) {
            throw new \InvalidArgumentException(sprintf(
                'Se esperaba instancia de %s o string, %s dado.',
                $class,
                get_debug_type($value)
            ));
        }

        return $vo->toString();
    }

    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        if ($value === null) {
            return null;
        }

        $class = $this->voClass();

        if ($value instanceof $class) {
            return $value;
        }

        // Crea el VO y aplica sus invariantes/validaciones
        return new $class((string)$value);
    }
}
