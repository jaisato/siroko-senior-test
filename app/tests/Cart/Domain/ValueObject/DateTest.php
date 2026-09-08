<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Exception\DateIsNotValid;
use Siroko\Cart\Domain\ValueObject\Date;

final class DateTest extends TestCase
{
    public function test_it_is_built_from_calendar_components(): void
    {
        $date = Date::createFromYearMonthAndDay(2026, 2, 28);

        self::assertSame('28/02/2026', $date->format());
        self::assertSame('2026-02-28', $date->format(Date::DATE_USA));
        self::assertSame('28/02/26', $date->format(Date::DATE_EUROPE_SHORT));
    }

    public function test_an_impossible_calendar_date_is_rejected(): void
    {
        $this->expectException(DateIsNotValid::class);
        $this->expectExceptionMessage('year "2026", month "2" and day "30"');

        Date::createFromYearMonthAndDay(2026, 2, 30);
    }

    public function test_it_is_built_from_a_string_or_a_date_time(): void
    {
        self::assertTrue(Date::createFromString('2026-09-05')->equalsTo(Date::createFromYearMonthAndDay(2026, 9, 5)));
        self::assertTrue(Date::createFromDateTime(new \DateTimeImmutable('2026-09-05 23:59:59'))->equalsTo(Date::createFromYearMonthAndDay(2026, 9, 5)));
    }

    public function test_a_string_that_is_not_a_date_is_rejected(): void
    {
        $this->expectException(DateIsNotValid::class);
        $this->expectExceptionMessage('does not have a valid format');

        Date::createFromString('not a date');
    }

    public function test_equality_and_time_representations(): void
    {
        $date = Date::createFromYearMonthAndDay(2026, 9, 5);

        self::assertTrue($date->equalsTo(Date::createFromYearMonthAndDay(2026, 9, 5)));
        self::assertFalse($date->equalsTo(Date::createFromYearMonthAndDay(2026, 9, 6)));
        self::assertSame('2026-09-05 00:00:00', $date->asDateTime()->format('Y-m-d H:i:s'));
        self::assertSame(Date::TIMEZONE, $date->asDateTime()->getTimezone()->getName());
        self::assertSame($date->asDateTime()->getTimestamp(), $date->asTimestamp());
    }

    public function test_today_is_today(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(Date::TIMEZONE));

        self::assertSame($now->format('Y-m-d'), Date::today()->format(Date::DATE_USA));
    }
}
