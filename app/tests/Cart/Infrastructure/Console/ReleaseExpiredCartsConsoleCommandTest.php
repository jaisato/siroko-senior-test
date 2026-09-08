<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Console;

use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bin/console cart:release-expired`, against the database: the command a
 * scheduler runs to give back the units of abandoned carts.
 */
final class ReleaseExpiredCartsConsoleCommandTest extends ApiTestCase
{
    public function test_it_releases_expired_pending_carts_and_leaves_the_rest_alone(): void
    {
        $product = $this->persistProduct(stock: 10);
        $expired = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 3]], new \DateTimeImmutable('-1 minute'));
        $alsoExpired = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]], new \DateTimeImmutable('-1 hour'));
        $fresh = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 2]], new \DateTimeImmutable('+30 minutes'));
        $noDeadline = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]]);
        $paid = $this->persistCartWithLines(CartStatus::PAID, [[$product, 1]]);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('Released 2 of 2 expired cart(s) in 1 batch(es).', $tester->getDisplay());

        self::assertSame(14, $this->stockOf($product), 'three plus one units came back');
        self::assertSame(CartStatus::CANCELED, $this->reloadCart($expired)->status()->toInt());
        self::assertSame(CartStatus::CANCELED, $this->reloadCart($alsoExpired)->status()->toInt());
        self::assertSame(CartStatus::PENDING, $this->reloadCart($fresh)->status()->toInt());
        self::assertSame(CartStatus::PENDING, $this->reloadCart($noDeadline)->status()->toInt());
        self::assertSame(CartStatus::PAID, $this->reloadCart($paid)->status()->toInt());
    }

    /** Running it again finds nothing: the sweep is idempotent. */
    public function test_a_second_run_releases_nothing_more(): void
    {
        $product = $this->persistProduct(stock: 10);
        $this->persistCartWithLines(CartStatus::PENDING, [[$product, 3]], new \DateTimeImmutable('-1 minute'));

        $this->tester()->execute([]);
        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('Released 0 of 0 expired cart(s)', $tester->getDisplay());
        self::assertSame(13, $this->stockOf($product), 'the units came back exactly once');
    }

    public function test_it_drains_the_backlog_in_batches(): void
    {
        $product = $this->persistProduct(stock: 10);
        for ($i = 0; $i < 5; ++$i) {
            $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]], new \DateTimeImmutable('-1 minute'));
        }

        $tester = $this->tester();
        $tester->execute(['--batch-size' => '2'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertStringContainsString('Released 5 of 5 expired cart(s) in 3 batch(es).', $tester->getDisplay());
        self::assertStringContainsString('Batch 1: 2 expired cart(s) found, 2 released.', $tester->getDisplay());
        self::assertStringContainsString('Batch 3: 1 expired cart(s) found, 1 released.', $tester->getDisplay());
        self::assertSame(15, $this->stockOf($product));
    }

    public function test_max_batches_caps_one_run(): void
    {
        $product = $this->persistProduct(stock: 10);
        for ($i = 0; $i < 5; ++$i) {
            $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]], new \DateTimeImmutable('-1 minute'));
        }

        $tester = $this->tester();
        $tester->execute(['--batch-size' => '2', '--max-batches' => '1']);

        self::assertStringContainsString('Released 2 of 2 expired cart(s) in 1 batch(es).', $tester->getDisplay());
        self::assertSame(12, $this->stockOf($product), 'the other three wait for the next run');
    }

    public function test_an_invalid_option_is_refused(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--batch-size' => 'many']));
        self::assertSame(Command::INVALID, $tester->execute(['--batch-size' => '0']));
        self::assertSame(Command::INVALID, $tester->execute(['--max-batches' => '-1']));
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel, 'the test client has booted the kernel');

        $application = new Application($kernel);
        $application->setAutoExit(false);

        return new CommandTester($application->find('cart:release-expired'));
    }
}
