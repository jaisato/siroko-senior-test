<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Service;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\ValueObject\DateTime;
use Siroko\Cart\Infrastructure\Service\Utilities;

final class UtilitiesTest extends TestCase
{
    public function test_short_text_is_returned_untouched(): void
    {
        self::assertSame('hello', Utilities::recortarPalabras('hello', 10));
        self::assertSame('hello', Utilities::recortarPalabras('hello', 5));
    }

    public function test_long_text_is_cut_and_marked(): void
    {
        self::assertSame('hello [...]', Utilities::recortarPalabras('hello world', 5));
    }

    public function test_milliseconds_between_two_date_times(): void
    {
        $from = DateTime::createFromDateAndTimeComponents(2026, 1, 1, 10, 0, 0);
        $to = DateTime::createFromDateAndTimeComponents(2026, 1, 1, 10, 0, 30);

        self::assertSame(30_000, Utilities::millisecondsBetweenTwoDateTime($to, $from));
        self::assertSame(-30_000, Utilities::millisecondsBetweenTwoDateTime($from, $to));
        self::assertSame(0, Utilities::millisecondsBetweenTwoDateTime($from, $from));
    }
}
