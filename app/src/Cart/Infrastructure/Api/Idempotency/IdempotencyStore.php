<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Idempotency;

interface IdempotencyStore
{
    /**
     * The record for an id, expired or not; the guard decides what an expired
     * one means.
     */
    public function find(string $id): ?IdempotencyRecord;

    /**
     * Writes a record, replacing whatever an expired one under the same id
     * held. Two requests presenting a new key at the same instant both
     * execute; the second write then fails on the primary key, which the
     * store treats as "already recorded by the other one" rather than as an
     * error, since the caller has a perfectly good response to return.
     *
     * Must work after the request's own transaction failed: a rejection is
     * exactly the kind of answer worth remembering.
     */
    public function save(IdempotencyRecord $record): void;

    /**
     * Deletes the records that expired at or before `$now`.
     *
     * @return int<0, max> how many went
     */
    public function purgeExpired(\DateTimeImmutable $now): int;
}
