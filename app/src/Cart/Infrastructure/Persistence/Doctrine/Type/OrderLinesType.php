<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use Siroko\Cart\Domain\ValueObject\OrderLine;

/**
 * Stores the lines of an order as a JSON document.
 *
 * The lines are a snapshot, read back as a whole and never queried by
 * column, which is what a JSON column is for; a table of their own would
 * add a join and an id to values that have no identity.
 *
 * @phpstan-import-type OrderLineArray from OrderLine
 */
final class OrderLinesType extends JsonType
{
    public const NAME = 'order_lines';

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @param mixed $value list<OrderLine>
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', 'array']);
        }

        $lines = [];

        foreach ($value as $line) {
            if (!$line instanceof OrderLine) {
                throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', OrderLine::class . '[]']);
            }

            $lines[] = $line->toArray();
        }

        return parent::convertToDatabaseValue($lines, $platform);
    }

    /**
     * @return list<OrderLine>|null
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): ?array
    {
        $decoded = parent::convertToPHPValue($value, $platform);

        if (null === $decoded) {
            return null;
        }

        if (!\is_array($decoded)) {
            throw ConversionException::conversionFailedFormat(\is_string($value) ? $value : '', self::NAME, 'a JSON list of order lines');
        }

        $lines = [];

        foreach ($decoded as $line) {
            if (!self::isLine($line)) {
                throw ConversionException::conversionFailedFormat(\is_string($value) ? $value : '', self::NAME, 'a JSON list of order lines');
            }

            $lines[] = OrderLine::fromArray($line);
        }

        return $lines;
    }

    /**
     * @phpstan-assert-if-true OrderLineArray $line
     */
    private static function isLine(mixed $line): bool
    {
        if (!\is_array($line)) {
            return false;
        }

        foreach (['productId', 'code', 'name'] as $string) {
            if (!isset($line[$string]) || !\is_string($line[$string])) {
                return false;
            }
        }

        if (!isset($line['quantity']) || !\is_int($line['quantity'])) {
            return false;
        }

        foreach (['unitPrice', 'lineTotal'] as $money) {
            if (
                !isset($line[$money]) || !\is_array($line[$money])
                || !isset($line[$money]['amount'], $line[$money]['currency'])
                || !\is_string($line[$money]['amount']) || !\is_string($line[$money]['currency'])
            ) {
                return false;
            }
        }

        return true;
    }
}
