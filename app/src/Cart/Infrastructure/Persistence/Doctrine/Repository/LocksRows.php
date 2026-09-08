<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;

/**
 * The one way a repository takes a row lock.
 *
 * A locked read has to hit the database, and hitting it is not enough: the
 * hydrator hands back whatever copy the identity map already holds for that
 * id and drops the row it has just read (UnitOfWork::createEntity() returns
 * early for a managed entity unless HINT_REFRESH is set). So a handler that
 * looked an entity up before opening its transaction - which the fast 404s
 * do, deliberately, so a request for something that is not there costs no
 * lock - took the lock and then went on reading the state it had before,
 * which is exactly what the lock exists to rule out.
 *
 * The shape it broke, concretely: `POST /v1/carts/{id}/items` reads the
 * product for its 404, and a price change in another currency commits in
 * between. The update sees no pending hold and succeeds; the add validates
 * the currency against the copy from before it, so the line joins a cart
 * priced in the old one. The response is built from that same stale copy and
 * looks right; every later read of the cart loads both currencies and fails
 * to add them up, which is a 409 on a cart that no endpoint can now fix.
 *
 * Refreshing is safe here because a locking read is the *first* thing these
 * handlers do with an entity: nothing has been changed on it yet that
 * overwriting could lose.
 */
trait LocksRows
{
    /**
     * @param Query<null, mixed> $query
     *
     * @return Query<null, mixed>
     */
    private function forUpdate(Query $query): Query
    {
        return $query
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true);
    }
}
