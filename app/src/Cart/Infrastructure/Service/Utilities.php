<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Service;

use Siroko\Cart\Domain\ValueObject\DateTime;

class Utilities
{
    public static function recortarPalabras(string $text, int $length): string
    {
        if (\strlen($text) <= $length) {
            return $text;
        }

        return \sprintf(
            '%s [...]',
            mb_substr(
                $text,
                0,
                $length,
            ),
        );
    }

    public static function millisecondsBetweenTwoDateTime(
        DateTime $firstDate,
        DateTime $secondDate,
    ): int {
        return (
            strtotime($firstDate->format('Y-m-d H:i:s'))
            - strtotime($secondDate->format('Y-m-d H:i:s'))
        ) * 1000;
    }
}
