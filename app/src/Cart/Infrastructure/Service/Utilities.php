<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Service;

use Siroko\Cart\Domain\ValueObject\DateTime;

use function array_filter;
use function explode;
use function implode;
use function in_array;
use function mb_substr;
use function sprintf;
use function str_replace;
use function strlen;
use function strpos;
use function strrpos;
use function strtotime;
use function substr;
use function trim;

use const ARRAY_FILTER_USE_BOTH;

class Utilities
{
    /**
     * @param string $text
     * @param int $length
     * @return string
     */
    public static function recortarPalabras(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return sprintf(
            '%s [...]',
            mb_substr(
                $text,
                0,
                $length
            )
        );
    }

    /**
     * @param DateTime $firstDate
     * @param DateTime $secondDate
     * @return int
     */
    public static function millisecondsBetweenTwoDateTime(
        DateTime $firstDate,
        DateTime $secondDate
    ): int {
        return (
                strtotime($firstDate->format('Y-m-d H:i:s')) -
                strtotime($secondDate->format('Y-m-d H:i:s'))
            ) * 1000;
    }
}
