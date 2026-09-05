<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

final class TimeIsNotValid extends \Exception
{
    public static function becauseHourMinutesAndSecondsCombinationIsNotValid(
        int $hour,
        int $minutes,
        int $seconds,
    ): self {
        return new self(
            \sprintf(
                'Combination of hour "%s", minutes"%s" and seconds "%s" is not valid',
                $hour,
                $minutes,
                $seconds,
            ),
        );
    }

    public static function becauseTimeStringDoesNotHaveAValidFormat(string $timeString): self
    {
        return new self(
            \sprintf(
                'String "%s" does not have a valid format',
                $timeString,
            ),
        );
    }
}
