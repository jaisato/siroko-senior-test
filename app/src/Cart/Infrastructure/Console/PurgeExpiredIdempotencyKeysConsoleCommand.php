<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Console;

use Psr\Clock\ClockInterface;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes the Idempotency-Key records past their TTL. Meant for a scheduler,
 * next to cart:release-expired; an expired record is ignored anyway, this
 * only keeps the table from growing without bound.
 */
#[AsCommand(
    name: 'idempotency:purge-expired',
    description: 'Delete the stored Idempotency-Key responses that are past their TTL',
)]
final class PurgeExpiredIdempotencyKeysConsoleCommand extends Command
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deleted = $this->store->purgeExpired($this->clock->now());

        $io->success(\sprintf('Purged %d expired idempotency record(s).', $deleted));

        return Command::SUCCESS;
    }
}
