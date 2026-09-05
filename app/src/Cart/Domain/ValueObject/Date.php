<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\DateIsNotValid;

use function time;

final class Date
{
    public const FAKER_METHOD = 'Date::today()';

    public const DATE_EUROPE = 'd/m/Y';

    public const DATE_USA = 'Y-m-d';

    public const DATE_EUROPE_SHORT = 'd/m/y';

    public const TIMESTAMP_FORMAT = 'U';

    public const TIMEZONE = 'Europe/Madrid';

    private int $year;
    private int $month;
    private int $day;

    private function __construct(int $year, int $month, int $day)
    {
        $this->year  = $year;
        $this->month = $month;
        $this->day   = $day;
    }

    /**
     * @throws DateIsNotValid
     */
    public static function createFromYearMonthAndDay(int $year, int $month, int $day): self
    {
        if (! checkdate($month, $day, $year)) {
            throw DateIsNotValid::becauseYearMonthAndDayCombinationIsNotValid($year, $month, $day);
        }

        return new self(
            $year,
            $month,
            $day,
        );
    }

    public static function createFromDateTime(\DateTimeInterface $dateTime): self
    {
        return self::createFromYearMonthAndDay(
            (int) $dateTime->format('Y'),
            (int) $dateTime->format('m'),
            (int) $dateTime->format('d'),
        );
    }

    /**
     * @throws DateIsNotValid
     */
    public static function createFromString(string $date): self
    {
        try {
            return self::createFromDateTime(
                new \DateTimeImmutable(
                    $date,
                    new \DateTimeZone(
                        self::TIMEZONE,
                    ),
                ),
            );
        } catch (\Throwable $e) {
            throw DateIsNotValid::becauseStringDoesNotHaveAValidFormat($date);
        }
    }

    public static function today(): self
    {
        return self::createFromDateTime(
            (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->setTimestamp(\time()),
        );
    }

    public function equalsTo(self $anotherDate): bool
    {
        return $this->year === $anotherDate->year
            && $this->month === $anotherDate->month
            && $this->day === $anotherDate->day;
    }

    public function format(string $format = self::DATE_EUROPE): string
    {
        return $this->asDateTime()->format($format);
    }

    public function asTimestamp(): int
    {
        return (int) $this->asDateTime()->format(self::TIMESTAMP_FORMAT);
    }

    /**
     * Midnight of this date in the domain time zone.
     *
     * The instant used to depend on the server's default time zone: the date
     * was built there and only then converted to Europe/Madrid, so on a UTC
     * host "05/09/2026" became 02:00 Madrid time and the timestamp shifted
     * with the host configuration.
     */
    public function asDateTime(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))
            ->setDate($this->year, $this->month, $this->day)
            ->setTime(0, 0);
    }
}
