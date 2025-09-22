<?php

namespace Siroko\Cart\Domain\ValueObject;

use Siroko\Cart\Domain\Exception\TimeIsNotValid;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final class Time
{
    public const FAKER_METHOD = 'Time::now()';

    public const TIME_FORMAT = 'H:i:s';

    public const TIME_FORMAT_WITHOUT_SECS = 'H:i';

    private const HOUR_MIN_VALUE = 0;

    private const HOUR_MAX_VALUE = 23;

    private const MINUTES_MIN_VALUE = 0;

    private const MINUTES_MAX_VALUE = 59;

    private const SECONDS_MIN_VALUE = 0;

    private const SECONDS_MAX_VALUE = 59;

    private int $hour;
    private int $minutes;
    private int $seconds;

    private function __construct(int $hour, int $minutes, int $seconds)
    {
        $this->hour    = $hour;
        $this->minutes = $minutes;
        $this->seconds = $seconds;
    }

    public function hour(): int
    {
        return $this->hour;
    }

    public function minutes(): int
    {
        return $this->minutes;
    }

    public function seconds(): int
    {
        return $this->seconds;
    }

    /**
     * @throws TimeIsNotValid
     */
    public static function createFromHourMinutesAndSeconds(int $hour, int $minutes, int $seconds): self
    {
        if ($hour < self::HOUR_MIN_VALUE || $hour > self::HOUR_MAX_VALUE) {
            throw TimeIsNotValid::becauseHourMinutesAndSecondsCombinationIsNotValid($hour, $minutes, $seconds);
        }

        if ($minutes < self::MINUTES_MIN_VALUE || $minutes > self::MINUTES_MAX_VALUE) {
            throw TimeIsNotValid::becauseHourMinutesAndSecondsCombinationIsNotValid($hour, $minutes, $seconds);
        }

        if ($seconds < self::SECONDS_MIN_VALUE || $seconds > self::SECONDS_MAX_VALUE) {
            throw TimeIsNotValid::becauseHourMinutesAndSecondsCombinationIsNotValid($hour, $minutes, $seconds);
        }

        return new self(
            $hour,
            $minutes,
            $seconds
        );
    }

    public static function createFromDateTime(DateTimeInterface $dateTime): self
    {
        return self::createFromHourMinutesAndSeconds(
            (int) $dateTime->format('H'),
            (int) $dateTime->format('i'),
            (int) $dateTime->format('s'),
        );
    }

    /**
     * @throws TimeIsNotValid
     */
    public static function createFromString(string $time): self
    {
        try {
            return self::createFromDateTime(
                new DateTimeImmutable(
                    $time
                )
            );
        } catch (Throwable $e) {
            throw TimeIsNotValid::becauseTimeStringDoesNotHaveAValidFormat($time);
        }
    }

    public static function now(): self
    {
        return self::createFromDateTime(
            new DateTimeImmutable()
        );
    }

    public static function beginningOfDay(): self
    {
        return new self(
            self::HOUR_MIN_VALUE,
            self::MINUTES_MIN_VALUE,
            self::SECONDS_MIN_VALUE
        );
    }

    public static function endOfDay(): self
    {
        return new self(
            self::HOUR_MAX_VALUE,
            self::MINUTES_MAX_VALUE,
            self::SECONDS_MAX_VALUE
        );
    }

    public function equalsTo(Time $anotherTime): bool
    {
        return $this->hour === $anotherTime->hour
            && $this->minutes === $anotherTime->minutes
            && $this->seconds === $anotherTime->seconds;
    }

    public function format(): string
    {
        $date = new DateTimeImmutable();
        $date = $date->setTime($this->hour, $this->minutes, $this->seconds);

        return $date->format(self::TIME_FORMAT);
    }

    public function asDateTime(): DateTimeImmutable
    {
        $date = new DateTimeImmutable();
        $date = $date->setTime($this->hour, $this->minutes, $this->seconds);

        return $date;
    }

    public function formatWithoutSeconds(): string
    {
        $date = new DateTimeImmutable();
        $date = $date->setTime($this->hour, $this->minutes, $this->seconds);

        return $date->format(self::TIME_FORMAT_WITHOUT_SECS);
    }
}
