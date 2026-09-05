<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Exception\DateTimeIsNotValid;
use Siroko\Cart\Domain\ValueObject\Date;
use Siroko\Cart\Domain\ValueObject\DateTime;
use Siroko\Cart\Domain\ValueObject\Time;

final class DateTimeTest extends TestCase
{
    public function test_it_is_built_from_components(): void
    {
        $dateTime = DateTime::createFromDateAndTimeComponents(2026, 9, 5, 14, 30, 15);

        self::assertSame('05/09/2026 14:30:15', $dateTime->format());
        self::assertSame('05/09/2026', $dateTime->dateAsString());
        self::assertSame('14:30:15', $dateTime->timeAsString());
        self::assertSame('14:30', $dateTime->timeWithoutSecoundsAsString());
        self::assertSame($dateTime->asDateTime()->getTimestamp(), $dateTime->asTimestamp());
    }

    public function test_invalid_components_are_rejected_with_one_message(): void
    {
        $this->expectException(DateTimeIsNotValid::class);
        $this->expectExceptionMessage('hour "25"');

        DateTime::createFromDateAndTimeComponents(2026, 9, 5, 25, 0, 0);
    }

    public function test_it_is_built_from_a_date_and_a_time(): void
    {
        $dateTime = DateTime::createFromDateAndTime(
            Date::createFromYearMonthAndDay(2026, 1, 2),
            Time::createFromHourMinutesAndSeconds(3, 4, 5),
        );

        self::assertSame('02/01/2026 03:04:05', $dateTime->format());
    }

    public function test_it_is_built_from_strings_formats_and_timestamps(): void
    {
        $fromString = DateTime::createFromString('2026-09-05 14:30:15');
        $fromFormat = DateTime::createFromFormat('05/09/2026 14:30:15');
        $fromTimestamp = DateTime::createFromTimestamp($fromString->asTimestamp());

        self::assertTrue($fromString->equalsTo($fromFormat));
        self::assertTrue($fromString->equalsTo($fromTimestamp));
        self::assertNull(DateTime::createFromFormatOrNull(null, DateTime::FORMAT_DATETIME_EUROPE));
        self::assertNull(DateTime::createFromTimestampOrNull(null));
        self::assertNull(DateTime::createFromTimestampOrNull(0));
        self::assertTrue($fromString->equalsTo(DateTime::createFromFormatOrNull('05/09/2026 14:30:15', DateTime::FORMAT_DATETIME_EUROPE) ?? self::fail()));
    }

    public function test_a_string_that_is_not_a_date_time_is_rejected(): void
    {
        $this->expectException(DateTimeIsNotValid::class);

        DateTime::createFromString('yesterday-ish');
    }

    public function test_a_value_that_does_not_match_the_format_is_rejected(): void
    {
        $this->expectException(DateTimeIsNotValid::class);

        DateTime::createFromFormat('2026-09-05', DateTime::FORMAT_DATETIME_EUROPE);
    }

    public function test_relative_construction_and_comparison(): void
    {
        $now = DateTime::now();
        $later = DateTime::createIn('+1 hour');

        self::assertTrue($now->isEarlierThan($later));
        self::assertFalse($later->isEarlierThan($now));
    }

    public function test_an_unparseable_modifier_is_rejected(): void
    {
        $this->expectException(DateTimeIsNotValid::class);

        DateTime::createIn('sometime');
    }

    public function test_bounds_of_the_day(): void
    {
        $dateTime = DateTime::createFromDateAndTimeComponents(2026, 9, 5, 14, 30, 15);

        self::assertSame('05/09/2026 00:00:00', $dateTime->toBeginningOfDay()->format());
        self::assertSame('05/09/2026 23:59:59', $dateTime->toEndOfDay()->format());
    }

    public function test_last_month_helpers_span_the_previous_month(): void
    {
        $first = DateTime::firstDayOfLastMonthBeginningOfDay();
        $last = DateTime::lastDayOfLastMonthEndOfDay();

        self::assertTrue($first->isEarlierThan($last));
        self::assertSame('00:00:00', $first->timeAsString());
        self::assertSame('23:59:59', $last->timeAsString());
        self::assertSame('01', $first->asDateTime()->format('d'));
        self::assertSame($first->asDateTime()->format('Y-m'), $last->asDateTime()->format('Y-m'));
    }
}
