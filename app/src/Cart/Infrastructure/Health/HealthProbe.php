<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Health;

/**
 * One dependency the service cannot do without, and a way to ask it.
 *
 * Implementations are tagged `app.health_probe` (see services/cart/
 * infrastructure.yaml) and HealthChecker runs every one of them for
 * GET /health and `bin/console app:health`.
 */
interface HealthProbe
{
    /**
     * The name the report lists the check under.
     */
    public function name(): string;

    /**
     * Returns when the dependency answers, throws when it does not. The
     * exception is the reason, and it belongs in the log: the response is
     * read by monitors and, quite possibly, by anyone, and a driver's message
     * can name hosts, users and passwords.
     *
     * @throws \Throwable
     */
    public function check(): void;
}
