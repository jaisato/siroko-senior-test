<?php

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\DateIsNotValid;
use Siroko\Cart\Domain\Exception\DateTimeIsNotValid;
use Siroko\Cart\Domain\Exception\TimeIsNotValid;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

use function strtotime;
use function time;

final class DateTime
{
    public const FORMAT_DATETIME_EUROPE = 'd/m/Y H:i:s';

    public const FORMAT_DATETIME_FLATPICKR = 'd/m/Y – H:i:s';

    public const FORMAT_DATETIME_SCHEMA = 'Y-m-dTH:i:s';
    public const FORMAT_TIMESTAMP = 'U';

    private Date $date;
    private Time $time;

    private function __construct(Date $date, Time $time)
    {
        $this->date = $date;
        $this->time = $time;
    }

    /**
     * @throws DateTimeIsNotValid
     */
    public static function createFromDateAndTimeComponents(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minutes,
        int $seconds
    ): self {
        try {
            return new self(
                Date::createFromYearMonthAndDay(
                    $year,
                    $month,
                    $day
                ),
                Time::createFromHourMinutesAndSeconds(
                    $hour,
                    $minutes,
                    $seconds
                )
            );
        } catch (DateIsNotValid | TimeIsNotValid $e) {
            throw DateTimeIsNotValid::becauseDateAndTimeComponentsAreNotValid(
                $year,
                $month,
                $day,
                $hour,
                $minutes,
                $seconds
            );
        }
    }

    public static function createFromDateAndTime(Date $date, Time $time): self
    {
        return new self(
            $date,
            $time
        );
    }

    public static function createFromDateTime(DateTimeInterface $dateTime): self
    {
        return self::createFromDateAndTime(
            Date::createFromDateTime($dateTime),
            Time::createFromDateTime($dateTime),
        );
    }

    /**
     * @throws DateTimeIsNotValid
     */
    public static function createFromString(string $dateTime): self
    {
        try {
            return self::createFromDateTime(
                new DateTimeImmutable(
                    $dateTime,
                    new DateTimeZone(Date::TIMEZONE)
                )
            );
        } catch (Throwable $e) {
            throw DateTimeIsNotValid::becauseDateTimeFormatIsNotValid($dateTime);
        }
    }

    /**
     * @throws DateTimeIsNotValid
     */
    public static function createFromFormat(string $dateTime, string $format = self::FORMAT_DATETIME_EUROPE): self
    {
        try {
            return self::createFromDateTime(
                DateTimeImmutable::createFromFormat(
                    $format,
                    $dateTime,
                    new DateTimeZone(Date::TIMEZONE)
                )
            );
        } catch (Throwable $e) {
            throw DateTimeIsNotValid::becauseDateTimeFormatIsNotValid($dateTime);
        }
    }

    /**
     * @throws DateTimeIsNotValid
     */
    public static function createFromFormatOrNull(?string $dateTime, string $format): ?self
    {
        if ($dateTime === null) {
            return null;
        }

        return self::createFromFormat($dateTime, $format);
    }

    /**
     * @throws DateTimeIsNotValid
     */
    public static function createFromTimestamp(int $timestamp): self
    {
        try {
            return self::createFromDateTime(
                DateTimeImmutable::createFromFormat(
                    'U',
                    (string) $timestamp,
                    new DateTimeZone(Date::TIMEZONE)
                )
            );
        } catch (Throwable $e) {
            throw DateTimeIsNotValid::becauseDateTimeFormatIsNotValid((string) $timestamp);
        }
    }

    /**
     * @throws DateTimeIsNotValid
     */
    public static function createFromTimestampOrNull(?int $timestamp): ?self
    {
        if ($timestamp === null || $timestamp === 0) {
            return null;
        }

        return self::createFromTimestamp($timestamp);
    }

    /**
     * @throws DateTimeIsNotValid
     */
    public static function createIn(string $modifier): self
    {
        if (strtotime($modifier) === false) {
            throw DateTimeIsNotValid::becauseDateTimeFormatIsNotValid($modifier);
        }

        return self::createFromDateTime(
            (new DateTimeImmutable('now', new DateTimeZone(Date::TIMEZONE)))
                ->setTimestamp(time())
                ->modify($modifier)
        );
    }

    public static function now(): self
    {
        return self::createFromDateTime(
            (new DateTimeImmutable('now', new DateTimeZone(Date::TIMEZONE)))->setTimestamp(time())
        );
    }

    public static function firstDayOfLastMonthBeginningOfDay(): self
    {
        return self::createFromDateTime(
            (
            new DateTimeImmutable(
                'first day of last month',
                new DateTimeZone(Date::TIMEZONE)
            )
            )->setTime(
                    0,
                    0,
                    0
                )
        );
    }

    public static function lastDayOfLastMonthEndOfDay(): self
    {
        return self::createFromDateTime(
            (
            new DateTimeImmutable(
                'last day of last month',
                new DateTimeZone(Date::TIMEZONE)
            )
            )->setTime(
                    23,
                    59,
                    59
                )
        );
    }

    public function toBeginningOfDay(): self
    {
        return new self(
            $this->date,
            Time::beginningOfDay()
        );
    }

    public function toEndOfDay(): self
    {
        return new self(
            $this->date,
            Time::endOfDay()
        );
    }

    public function equalsTo(DateTime $anotherDateTime): bool
    {
        return $this->date->equalsTo($anotherDateTime->date)
            && $this->time->equalsTo($anotherDateTime->time);
    }

    public function isEarlierThan(DateTime $anotherDate): bool
    {
        return $this->asDateTime() < $anotherDate->asDateTime();
    }

    public function format(string $format = self::FORMAT_DATETIME_EUROPE): string
    {
        return $this->asDateTime()->format($format);
    }

    public function asTimestamp(): int
    {
        return (int) $this->asDateTime()->format(self::FORMAT_TIMESTAMP);
    }

    public function dateAsString(): string
    {
        return $this->date->format();
    }

    public function timeAsString(): string
    {
        return $this->time->format();
    }

    public function timeWithoutSecoundsAsString(): string
    {
        return $this->time->formatWithoutSeconds();
    }

    public function asDateTime(): DateTimeInterface
    {
        $dateTime = $this->date->asDateTime();
        $dateTime = $dateTime->setTime(
            $this->time->hour(),
            $this->time->minutes(),
            $this->time->seconds(),
        );

        return $dateTime;
    }
}
