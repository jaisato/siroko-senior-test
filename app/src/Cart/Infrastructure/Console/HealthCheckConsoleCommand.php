<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Console;

use Siroko\Cart\Infrastructure\Health\HealthChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The checks of GET /health, for the containers that have no web server: the
 * php-fpm, worker and scheduler services run it as their healthcheck (see
 * docker-compose.yaml and the prod image's HEALTHCHECK), where an exit code
 * is the whole answer.
 */
#[AsCommand(
    name: 'app:health',
    description: 'Check the dependencies the service needs (database, message queue): exit code 0 when all answer, 1 otherwise',
)]
final class HealthCheckConsoleCommand extends Command
{
    public function __construct(private readonly HealthChecker $checker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Runs the same checks as <info>GET /health</info>: the database answers a trivial query and
            the message transport can be asked for its queue length (with the Doctrine transport,
            <info>messenger_messages</info> is reachable). One line per check; the reason a check
            failed is in the log, not in the output.

            Meant for container healthchecks:

                HEALTHCHECK CMD php bin/console app:health

            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->checker->check();

        foreach ($report->checks() as $name => $status) {
            $output->writeln(\sprintf('%-10s %s', $name, $status));
        }

        return $report->isHealthy() ? Command::SUCCESS : Command::FAILURE;
    }
}
