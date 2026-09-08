<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Idempotency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

/**
 * Keeps the records in the `idempotency_key` table through plain DBAL
 * statements rather than the EntityManager.
 *
 * The key is claimed before its request runs and answered afterwards, and
 * that answer is often a rejection: the domain refused the checkout, the cart
 * was not found. By then the EntityManager has rolled the business
 * transaction back and closed itself, and a store built on its unit of work
 * could not write anything any more - the 409 the client is owed would turn
 * into a 500. The connection, unlike the manager, is still perfectly usable.
 *
 * The claim is what makes two simultaneous originals safe: it is an INSERT on
 * the primary key, so exactly one of them wins it and the others are told the
 * request is already in flight. Writing the record afterwards, as this store
 * used to, only ever stopped *retries* - two clients racing with one key both
 * created a cart and both reserved stock.
 *
 * The table itself is described by the ORM mapping of IdempotencyRecord, so
 * it is created, validated and migrated with the rest of the schema.
 */
final class DoctrineIdempotencyStore implements IdempotencyStore
{
    private const TABLE = 'idempotency_key';

    /** @var array<string, string> DBAL type of every column */
    private const TYPES = [
        'id' => Types::STRING,
        'scope' => Types::STRING,
        'request_key' => Types::STRING,
        'fingerprint' => Types::STRING,
        'response_status' => Types::SMALLINT,
        'response_content_type' => Types::STRING,
        'response_body' => Types::TEXT,
        'created_at' => Types::DATETIME_IMMUTABLE,
        'expires_at' => Types::DATETIME_IMMUTABLE,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public function find(string $id): ?IdempotencyRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT scope, request_key, fingerprint, response_status, response_content_type, response_body, created_at, expires_at FROM ' . self::TABLE . ' WHERE id = ?',
            [$id],
        );

        if (false === $row) {
            return null;
        }

        return IdempotencyRecord::restore(
            $id,
            (string) $row['scope'],
            (string) $row['request_key'],
            (string) $row['fingerprint'],
            null === $row['response_status'] ? null : (int) $row['response_status'],
            null === $row['response_content_type'] ? null : (string) $row['response_content_type'],
            null === $row['response_body'] ? null : (string) $row['response_body'],
            $this->dateTime($row['created_at']),
            $this->dateTime($row['expires_at']),
        );
    }

    public function claim(IdempotencyRecord $pending): bool
    {
        try {
            $this->connection->insert(self::TABLE, ['id' => $pending->id()] + $this->columns($pending), self::TYPES);

            return true;
        } catch (UniqueConstraintViolationException $e) {
            $this->logger->debug('Idempotency key already claimed; trying to take over an expired record', ['id' => $pending->id(), 'exception' => $e]);
        }

        // A record is there. Only an expired one may be taken over, and the
        // deadline is part of the UPDATE rather than of a preceding SELECT, so
        // that of two requests finding the same expired record exactly one
        // walks away with it.
        $taken = $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET scope = :scope, request_key = :request_key, fingerprint = :fingerprint,'
            . ' response_status = NULL, response_content_type = NULL, response_body = NULL,'
            . ' created_at = :created_at, expires_at = :expires_at'
            . ' WHERE id = :id AND expires_at <= :now',
            [
                'id' => $pending->id(),
                'scope' => $pending->scope(),
                'request_key' => $pending->requestKey(),
                'fingerprint' => $pending->fingerprint(),
                'created_at' => $pending->createdAt(),
                'expires_at' => $pending->expiresAt(),
                'now' => $pending->createdAt(),
            ],
            [
                'id' => Types::STRING,
                'scope' => Types::STRING,
                'request_key' => Types::STRING,
                'fingerprint' => Types::STRING,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
                'now' => Types::DATETIME_IMMUTABLE,
            ],
        );

        return $taken > 0;
    }

    public function complete(IdempotencyRecord $record): void
    {
        $this->connection->update(self::TABLE, $this->columns($record), ['id' => $record->id()], self::TYPES);
    }

    public function release(string $id): void
    {
        // Only an unanswered claim: an answered one is what retries replay.
        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE id = ? AND response_status IS NULL',
            [$id],
        );
    }

    /** @return array<string, mixed> every column but the id */
    private function columns(IdempotencyRecord $record): array
    {
        return [
            'scope' => $record->scope(),
            'request_key' => $record->requestKey(),
            'fingerprint' => $record->fingerprint(),
            'response_status' => $record->responseStatus(),
            'response_content_type' => $record->responseContentType(),
            'response_body' => $record->responseBody(),
            'created_at' => $record->createdAt(),
            'expires_at' => $record->expiresAt(),
        ];
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $deleted = $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE expires_at <= ?',
            [$now],
            [Types::DATETIME_IMMUTABLE],
        );

        return max(0, (int) $deleted);
    }

    private function dateTime(mixed $value): \DateTimeImmutable
    {
        $converted = $this->connection->convertToPHPValue($value, Types::DATETIME_IMMUTABLE);

        if (!$converted instanceof \DateTimeImmutable) {
            throw new \UnexpectedValueException(\sprintf('Expected a datetime from %s, got %s.', self::TABLE, get_debug_type($value)));
        }

        return $converted;
    }
}
