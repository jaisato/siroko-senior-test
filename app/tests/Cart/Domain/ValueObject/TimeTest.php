<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Exception\TimeIsNotValid;
use Siroko\Cart\Domain\ValueObject\Time;

final class TimeTest extends TestCase
{
    public function test_it_is_built_from_components(): void
    {
        $time = Time::createFromHourMinutesAndSeconds(9, 5, 7);

        self::assertSame(9, $time->hour());
        self::assertSame(5, $time->minutes());
        self::assertSame(7, $time->seconds());
        self::assertSame('09:05:07', $time->format());
        self::assertSame('09:05', $time->formatWithoutSeconds());
        self::assertSame('09:05:07', $time->asDateTime()->format('H:i:s'));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function outOfRange(): iterable
    {
        yield 'hour 24' => [24, 0, 0];
        yield 'negative hour' => [-1, 0, 0];
        yield 'minute 60' => [0, 60, 0];
        yield 'second 60' => [0, 0, 60];
    }

    #[DataProvider('outOfRange')]
    public function test_components_out_of_range_are_rejected(int $hour, int $minutes, int $seconds): void
    {
        $this->expectException(TimeIsNotValid::class);

        Time::createFromHourMinutesAndSeconds($hour, $minutes, $seconds);
    }

    public function test_it_is_built_from_strings_and_date_times(): void
    {
        self::assertSame('13:45:00', Time::createFromString('13:45')->format());
        self::assertSame('13:45:10', Time::createFromDateTime(new \DateTimeImmutable('2026-01-01 13:45:10'))->format());
    }

    public function test_a_string_that_is_not_a_time_is_rejected(): void
    {
        $this->expectException(TimeIsNotValid::class);

        Time::createFromString('half past nothing');
    }

    public function test_bounds_of_the_day_and_equality(): void
    {
        self::assertSame('00:00:00', Time::beginningOfDay()->format());
        self::assertSame('23:59:59', Time::endOfDay()->format());
        self::assertTrue(Time::beginningOfDay()->equalsTo(Time::createFromHourMinutesAndSeconds(0, 0, 0)));
        self::assertFalse(Time::beginningOfDay()->equalsTo(Time::endOfDay()));
    }

    public function test_now_is_a_valid_time(): void
    {
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', Time::now()->format());
    }
}
