<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Console;

use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyGuard;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyRecord;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyStore;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\JsonResponse;

final class PurgeExpiredIdempotencyKeysConsoleCommandTest extends KernelTestCase
{
    public function test_it_purges_the_expired_records_and_reports_how_many(): void
    {
        $kernel = self::bootKernel();
        $store = static::getContainer()->get(IdempotencyStore::class);
        foreach (['old' => '-2 days', 'fresh' => 'now'] as $key => $when) {
            $at = new \DateTimeImmutable($when);
            $claim = IdempotencyRecord::claim(IdempotencyGuard::recordId('', $key), '', $key, 'fp', $at, new \DateInterval('PT1H'));
            $store->claim($claim);
            $store->complete($claim->completedWith(new JsonResponse([]), $at, new \DateInterval('PT1H')));
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('idempotency:purge-expired'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Purged 1 expired idempotency record(s).', $tester->getDisplay());
        self::assertNull($store->find(IdempotencyGuard::recordId('', 'old')));
        self::assertNotNull($store->find(IdempotencyGuard::recordId('', 'fresh')));
    }
}
