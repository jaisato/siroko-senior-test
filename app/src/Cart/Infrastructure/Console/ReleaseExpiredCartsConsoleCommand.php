<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Console;

use Siroko\Cart\Application\Command\Cart\ReleaseExpiredCartsCommand;
use Siroko\Cart\Application\Dto\Cart\ReleasedCarts;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Releases the stock held by pending carts whose reservation has lapsed.
 *
 * Meant for a scheduler - cron every minute, or the `scheduler` compose
 * service. Safe to run at any time and from several places at once: each cart
 * is re-checked under its row lock before it is touched.
 */
#[AsCommand(
    name: 'cart:release-expired',
    description: 'Cancel pending carts whose reservation has expired and return their units to stock',
)]
final class ReleaseExpiredCartsConsoleCommand extends Command
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, \sprintf('Carts per transaction batch (1-%d)', ReleaseExpiredCartsCommand::MAX_BATCH_SIZE), (string) ReleaseExpiredCartsCommand::DEFAULT_BATCH_SIZE)
            ->addOption('max-batches', 'm', InputOption::VALUE_REQUIRED, 'Stop after this many batches; 0 runs until the backlog is drained', '0')
            ->setHelp(<<<'HELP'
                Cancels every pending cart whose <info>expires_at</info> has passed and gives the units
                its lines hold back to the products, in batches of <info>--batch-size</info> carts, one
                transaction per cart. It stops when a batch comes back short or after
                <info>--max-batches</info> batches.

                Schedule it, for instance every minute:

                    * * * * * php bin/console cart:release-expired

                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batchSize = self::integerOption($input, 'batch-size');
        $maxBatches = self::integerOption($input, 'max-batches');

        if (null === $batchSize || null === $maxBatches || $maxBatches < 0) {
            $io->error('--batch-size and --max-batches must be non-negative integers.');

            return Command::INVALID;
        }

        try {
            $command = new ReleaseExpiredCartsCommand($batchSize);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $candidates = 0;
        $released = 0;
        $batches = 0;

        do {
            $result = $this->commandBus->handle($command);

            if (!$result instanceof ReleasedCarts) {
                throw new \LogicException(\sprintf('%s must answer with %s.', ReleaseExpiredCartsCommand::class, ReleasedCarts::class));
            }

            ++$batches;
            $candidates += $result->candidates;
            $released += $result->released;

            $io->writeln(\sprintf('Batch %d: %d expired cart(s) found, %d released.', $batches, $result->candidates, $result->released), OutputInterface::VERBOSITY_VERBOSE);
        } while (!$result->isShortOf($batchSize) && (0 === $maxBatches || $batches < $maxBatches));

        $io->success(\sprintf('Released %d of %d expired cart(s) in %d batch(es).', $released, $candidates, $batches));

        return Command::SUCCESS;
    }

    private static function integerOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);

        if (\is_int($value)) {
            return $value;
        }

        if (!\is_string($value) || 1 !== preg_match('/^\d{1,9}$/', $value)) {
            return null;
        }

        return (int) $value;
    }
}
