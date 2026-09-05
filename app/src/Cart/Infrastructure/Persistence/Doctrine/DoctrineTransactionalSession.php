<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

final class DoctrineTransactionalSession implements TransactionalSession
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Nested flushes inside the callable join this transaction instead of
     * committing on their own, so the whole operation commits once or not at
     * all.
     */
    public function executeAtomically(callable $operation): mixed
    {
        return $this->em->wrapInTransaction($operation);
    }
}
