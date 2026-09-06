<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Repository;

use Brick\Math\BigDecimal;
use Siroko\Cart\Domain\Exception\InvalidProductCriteriaException;

/**
 * What a product listing is filtered and ordered by.
 *
 * Built once, at the edge, from the query string; the repository trusts it.
 * Prices are compared by amount only: the catalogue is meant to be priced in
 * one currency, and a bound has no currency of its own.
 */
final class ProductCriteria
{
    public const MAX_TEXT_LENGTH = 100;

    /**
     * A non-negative amount with up to four decimals - the scale of the price
     * column - and no more digits than it holds.
     */
    private const AMOUNT = '/^\d{1,15}(\.\d{1,4})?$/';

    /**
     * @param string|null $text     matched, case-insensitively, against the name and the code
     * @param string|null $minPrice inclusive lower bound of the amount
     * @param string|null $maxPrice inclusive upper bound of the amount
     * @param bool|null   $inStock  true: units available; false: none; null: both
     */
    private function __construct(
        public readonly ?string $text,
        public readonly ?string $minPrice,
        public readonly ?string $maxPrice,
        public readonly ?bool $inStock,
        public readonly ProductSort $sort,
    ) {}

    public static function all(): self
    {
        return new self(null, null, null, null, ProductSort::NameAsc);
    }

    /**
     * @param string|null $sort one of ProductSort::values(); null means by name
     *
     * @throws InvalidProductCriteriaException
     */
    public static function of(
        ?string $text = null,
        ?string $minPrice = null,
        ?string $maxPrice = null,
        ?bool $inStock = null,
        ?string $sort = null,
    ): self {
        $text = null === $text ? null : trim($text);

        if ('' === $text) {
            $text = null;
        }

        if (null !== $text && mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw InvalidProductCriteriaException::textTooLong(self::MAX_TEXT_LENGTH);
        }

        $minPrice = self::amount($minPrice, 'minPrice');
        $maxPrice = self::amount($maxPrice, 'maxPrice');

        // Compared as decimals, not as floats. The pattern above admits fifteen
        // integral digits and four decimal ones, which is more precision than
        // a double carries: cast, 999999999999999.9999 and .9998 come out
        // equal, the inverted range goes unreported, and the repository then
        // applies two contradictory predicates and answers an empty page as
        // though that were the truth about the catalogue.
        if (null !== $minPrice && null !== $maxPrice && BigDecimal::of($minPrice)->isGreaterThan(BigDecimal::of($maxPrice))) {
            throw InvalidProductCriteriaException::invertedPriceRange();
        }

        $order = null === $sort ? ProductSort::NameAsc : ProductSort::tryFrom($sort);

        if (null === $order) {
            throw InvalidProductCriteriaException::unknownSort(ProductSort::values());
        }

        return new self($text, $minPrice, $maxPrice, $inStock, $order);
    }

    public function isEmpty(): bool
    {
        return null === $this->text && null === $this->minPrice && null === $this->maxPrice && null === $this->inStock;
    }

    /**
     * @throws InvalidProductCriteriaException
     */
    private static function amount(?string $value, string $name): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $value = trim($value);

        if (1 !== preg_match(self::AMOUNT, $value)) {
            throw InvalidProductCriteriaException::malformedAmount($name);
        }

        return $value;
    }
}
