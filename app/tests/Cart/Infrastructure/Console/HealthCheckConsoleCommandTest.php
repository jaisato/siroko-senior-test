<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Console;

use Siroko\Cart\Infrastructure\Health\MessengerProbe;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;
use Siroko\Tests\Cart\Infrastructure\Health\FailingProbe;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bin/console app:health`: the container healthcheck of the php, worker and
 * scheduler services, where the exit code is the whole answer.
 */
final class HealthCheckConsoleCommandTest extends ApiTestCase
{
    public function test_it_lists_every_check_and_succeeds_when_all_pass(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertMatchesRegularExpression('/^database\s+ok$/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^messenger\s+ok$/m', $tester->getDisplay());
    }

    public function test_it_fails_when_a_dependency_is_down(): void
    {
        static::getContainer()->set(MessengerProbe::class, new FailingProbe('messenger'));
        $tester = $this->tester();

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertMatchesRegularExpression('/^database\s+ok$/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^messenger\s+fail$/m', $tester->getDisplay());
        self::assertStringNotContainsString('hunter2', $tester->getDisplay(), 'the reason is for the log');
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel, 'the test client has booted the kernel');

        $application = new Application($kernel);
        $application->setAutoExit(false);

        return new CommandTester($application->find('app:health'));
    }
}
