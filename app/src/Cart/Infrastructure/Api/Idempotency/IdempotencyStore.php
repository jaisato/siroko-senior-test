<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Idempotency;

interface IdempotencyStore
{
    /**
     * The record for an id, expired or not, answered or still pending; the
     * guard decides what each of those means.
     */
    public function find(string $id): ?IdempotencyRecord;

    /**
     * Takes ownership of a key *before* its request runs, so that a second
     * request presenting it finds the claim and does not execute the write a
     * second time. An expired record under the same id is taken over.
     *
     * @return bool true when this caller now owns the key and must run the
     *              request; false when somebody else got there first
     */
    public function claim(IdempotencyRecord $pending): bool;

    /**
     * Stores the answer the claimed request produced, so later retries replay
     * it.
     *
     * Must work after the request's own transaction failed: a rejection is
     * exactly the kind of answer worth remembering, and by then the
     * EntityManager is closed (see DoctrineIdempotencyStore for why that
     * matters).
     */
    public function complete(IdempotencyRecord $record): void;

    /**
     * Gives a claim back without an answer, so the key can be retried: the
     * request blew up, or failed in a way that says nothing about whether
     * retrying would too. A claim that was already answered stays.
     */
    public function release(string $id): void;

    /**
     * Deletes the records that expired at or before `$now`.
     *
     * @return int<0, max> how many went
     */
    public function purgeExpired(\DateTimeImmutable $now): int;
}
