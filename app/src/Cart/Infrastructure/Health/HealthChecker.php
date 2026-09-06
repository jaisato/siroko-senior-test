<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Health;

use Psr\Log\LoggerInterface;

/**
 * Runs every probe and writes the report GET /health and app:health answer
 * with.
 *
 * A probe that throws marks its check as failed and goes on to the next one,
 * so a single request tells the whole story: which dependencies are up and
 * which are not. What went wrong is logged with the exception attached and
 * stays out of the report.
 */
final class HealthChecker
{
    /**
     * @param iterable<HealthProbe> $probes
     */
    public function __construct(
        private readonly iterable $probes,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws \LogicException when no probe is registered: a report with nothing checked would say "healthy" about nothing
     */
    public function check(): HealthReport
    {
        $checks = [];

        foreach ($this->probes as $probe) {
            try {
                $probe->check();
                $checks[$probe->name()] = HealthReport::OK;
            } catch (\Throwable $e) {
                $this->logger->error('Health check failed', ['check' => $probe->name(), 'exception' => $e]);
                $checks[$probe->name()] = HealthReport::FAIL;
            }
        }

        if ([] === $checks) {
            throw new \LogicException('No health probe is registered; tag the probes with "app.health_probe".');
        }

        return new HealthReport($checks);
    }
}
