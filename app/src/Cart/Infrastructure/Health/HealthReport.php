<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Health;

/**
 * The outcome of every probe, and the verdict they add up to.
 *
 * Only the verdicts travel: which check failed, never why. The reason is in
 * the log, where the people who can act on it read it.
 */
final class HealthReport
{
    public const OK = 'ok';

    public const FAIL = 'fail';

    /**
     * @param array<string, string> $checks check name => OK|FAIL
     */
    public function __construct(private readonly array $checks) {}

    public function isHealthy(): bool
    {
        return !\in_array(self::FAIL, $this->checks, true);
    }

    /**
     * @return array<string, string>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return array{status: string, checks: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->isHealthy() ? self::OK : self::FAIL,
            'checks' => $this->checks,
        ];
    }
}
