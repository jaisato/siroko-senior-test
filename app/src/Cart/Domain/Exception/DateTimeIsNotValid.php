<?php

namespace Siroko\Cart\Domain\Exception;

use function sprintf;

final class DateTimeIsNotValid extends \Exception
{
    public static function becauseDateAndTimeComponentsAreNotValid(
        int $year,
        int $month,
        int $day,
        int $hours,
        int $minutes,
        int $seconds
    ): self {
        return new self(
            sprintf(
                'Combination of year "%s", month "%s", day "%s", hour "%s", minutes "%s" and seconds "%s" is not valid',
                $year,
                $month,
                $day,
                $hours,
                $minutes,
                $seconds
            )
        );
    }

    public static function becauseDateTimeFormatIsNotValid(string $dateTimeFormat): self
    {
        return new self(
            sprintf(
                'String "%s" does not have a valid format',
                $dateTimeFormat
            )
        );
    }
}
