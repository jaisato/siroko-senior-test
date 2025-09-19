<?php

namespace Siroko\Cart\Domain\Exception;

use function sprintf;

final class DateIsNotValid extends \Exception
{
    public static function becauseYearMonthAndDayCombinationIsNotValid(int $year, int $month, int $day): self
    {
        return new self(
            sprintf(
                'Combination of year "%s", month "%s" and day "%s" is not valid',
                $year,
                $month,
                $day
            )
        );
    }

    public static function becauseStringDoesNotHaveAValidFormat(string $dateString): self
    {
        return new self(
            sprintf(
                'String "%s" does not have a valid format',
                $dateString
            )
        );
    }
}
