<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto;

use Siroko\Cart\Domain\ValueObject\Price;

/**
 * Renders a price the way the API has always shown it: localised for Spain,
 * e.g. "19,99 €".
 *
 * The domain value object used to do this itself, through `formatTo()` - a
 * method that does not exist in the pinned brick/money release (it is
 * `formatToLocale()`), which broke every product endpoint. Presentation is not
 * the domain's concern anyway; it lives next to the read models it serves.
 */
final class PriceFormatter
{
    public const LOCALE = 'es_ES';

    public static function format(Price $price): string
    {
        return $price->toMoney()->formatToLocale(self::LOCALE);
    }
}
