<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Health;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Infrastructure\Health\HealthChecker;
use Siroko\Tests\Cart\Application\Command\Order\RecordingLogger;

final class HealthCheckerTest extends TestCase
{
    public function test_every_probe_passing_is_a_healthy_report_and_nothing_is_logged(): void
    {
        $logger = new RecordingLogger();
        $checker = new HealthChecker([new PassingProbe('database'), new PassingProbe('messenger')], $logger);

        $report = $checker->check();

        self::assertTrue($report->isHealthy());
        self::assertSame(['status' => 'ok', 'checks' => ['database' => 'ok', 'messenger' => 'ok']], $report->toArray());
        self::assertSame([], $logger->records);
    }

    /**
     * One failure does not stop the others from being checked, marks the
     * report as failed, and puts the reason in the log - only there.
     */
    public function test_a_failing_probe_is_reported_by_name_and_explained_in_the_log_only(): void
    {
        $logger = new RecordingLogger();
        $failure = new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused (mysql://siroko:hunter2@db:3306)');
        $checker = new HealthChecker([new PassingProbe('database'), new FailingProbe('messenger', $failure)], $logger);

        $report = $checker->check();

        self::assertFalse($report->isHealthy());
        self::assertSame(['status' => 'fail', 'checks' => ['database' => 'ok', 'messenger' => 'fail']], $report->toArray());
        self::assertStringNotContainsString('hunter2', json_encode($report->toArray(), \JSON_THROW_ON_ERROR));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('messenger', $logger->records[0]['context']['check']);
        self::assertSame($failure, $logger->records[0]['context']['exception']);
    }

    /** A report about nothing would read as "healthy"; that is a wiring error and says so. */
    public function test_no_probe_at_all_is_a_misconfiguration(): void
    {
        $checker = new HealthChecker([], new RecordingLogger());

        $this->expectException(\LogicException::class);

        $checker->check();
    }
}
