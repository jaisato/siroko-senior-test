<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Repository;

/**
 * The orders a product listing can be asked for. The API spells them the
 * usual way: a field name, with a leading `-` for descending.
 */
enum ProductSort: string
{
    case NameAsc = 'name';
    case NameDesc = '-name';
    case PriceAsc = 'price';
    case PriceDesc = '-price';
    case CodeAsc = 'code';
    case CodeDesc = '-code';

    public function isDescending(): bool
    {
        return str_starts_with($this->value, '-');
    }

    /**
     * @return 'name'|'price'|'code'
     */
    public function field(): string
    {
        return match ($this) {
            self::NameAsc, self::NameDesc => 'name',
            self::PriceAsc, self::PriceDesc => 'price',
            self::CodeAsc, self::CodeDesc => 'code',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $sort): string => $sort->value, self::cases());
    }
}
