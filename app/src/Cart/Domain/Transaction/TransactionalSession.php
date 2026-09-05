<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Transaction;

/**
 * Runs several writes as one unit.
 *
 * The `write` command bus is configured with
 * `tactician.middleware.doctrine_rollback_only`, which marks an open
 * transaction for rollback when a handler throws but never opens one. Each
 * repository call flushes on its own, so a handler that writes twice commits
 * twice: if the second fails, the first stays.
 *
 * A handler that needs both writes to land together asks for it explicitly
 * through this port rather than reaching for the EntityManager, which the
 * application layer does not know about.
 */
interface TransactionalSession
{
    /**
     * @template T
     *
     * @param callable():T $operation
     *
     * @return T
     */
    public function executeAtomically(callable $operation): mixed;
}
