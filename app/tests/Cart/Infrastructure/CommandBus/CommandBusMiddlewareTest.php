<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\CommandBus;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Which middleware each bus carries, asserted against the configuration.
 *
 * It is a configuration fact and it is checked as one: the middleware chain a
 * Tactician bus is built with is a closure by the time the container is
 * compiled, so nothing at runtime can be asked what is in it - and the one
 * defect here was invisible from inside a handler for exactly that reason.
 *
 * `tactician.middleware.doctrine` opens a transaction around the whole handler.
 * A handler that then calls `TransactionalSession::executeAtomically()` only
 * nests inside it: nothing it writes is durable until the handler returns, and
 * a side effect the handler performs between two of those calls happens before
 * any of them has committed. `doctrine_rollback_only` opens nothing and only
 * marks an open transaction for rollback when a handler throws, which is what
 * lets a handler own its own transactions.
 */
final class CommandBusMiddlewareTest extends TestCase
{
    private const string CONFIG = __DIR__ . '/../../../../config/packages/league_tactician.yaml';

    /** Opens a transaction around every handler on the bus it is added to. */
    private const string IMPLICIT_TRANSACTION = 'tactician.middleware.doctrine';

    /**
     * The order confirmation runs here, and its design is three steps: commit
     * the decision, send, record the send. Under an outer transaction the
     * notification went out before any of them was durable - a commit that
     * failed afterwards rolled both timestamps back while the customer had
     * already been told, and the queue retried and told them again.
     */
    public function test_the_cli_bus_does_not_wrap_its_handlers_in_a_transaction(): void
    {
        self::assertNotContains(self::IMPLICIT_TRANSACTION, self::middlewareOf('cli'));
        self::assertContains('tactician.middleware.doctrine_rollback_only', self::middlewareOf('cli'));
    }

    /**
     * The same rule on the bus the API writes through, where it has always
     * held: each repository call flushes on its own and a handler that needs
     * two writes together asks for it explicitly.
     */
    public function test_the_write_bus_does_not_either(): void
    {
        self::assertNotContains(self::IMPLICIT_TRANSACTION, self::middlewareOf('write'));
        self::assertContains('tactician.middleware.doctrine_rollback_only', self::middlewareOf('write'));
    }

    /** Every bus ends in the handler, and none of them may skip the lock. */
    public function test_every_bus_locks_and_ends_in_the_handler(): void
    {
        foreach (['read', 'write', 'cli'] as $bus) {
            $middleware = self::middlewareOf($bus);

            self::assertSame('tactician.middleware.locking', $middleware[0] ?? null, $bus);
            self::assertSame('tactician.middleware.command_handler', end($middleware) ?: null, $bus);
        }
    }

    /** @return list<string> */
    private static function middlewareOf(string $bus): array
    {
        /** @var array{tactician: array{commandbus: array<string, array{middleware: list<string>}>}} $config */
        $config = Yaml::parseFile(self::CONFIG);

        return $config['tactician']['commandbus'][$bus]['middleware'];
    }
}
