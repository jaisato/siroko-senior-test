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
 * The record is written after the request it remembers has been answered,
 * and that answer is often a rejection: the domain refused the checkout, the
 * cart was not found. By then the EntityManager has rolled the business
 * transaction back and closed itself, and a store built on its unit of work
 * could not write anything any more - the 409 the client is owed would turn
 * into a 500. The connection, unlike the manager, is still perfectly usable.
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
            (int) $row['response_status'],
            (string) $row['response_content_type'],
            (string) $row['response_body'],
            $this->dateTime($row['created_at']),
            $this->dateTime($row['expires_at']),
        );
    }

    public function save(IdempotencyRecord $record): void
    {
        $columns = [
            'scope' => $record->scope(),
            'request_key' => $record->requestKey(),
            'fingerprint' => $record->fingerprint(),
            'response_status' => $record->responseStatus(),
            'response_content_type' => $record->responseContentType(),
            'response_body' => $record->responseBody(),
            'created_at' => $record->createdAt(),
            'expires_at' => $record->expiresAt(),
        ];

        // An expired record under the same id is replaced by the new answer.
        if ($this->connection->update(self::TABLE, $columns, ['id' => $record->id()], self::TYPES) > 0) {
            return;
        }

        try {
            $this->connection->insert(self::TABLE, ['id' => $record->id()] + $columns, self::TYPES);
        } catch (UniqueConstraintViolationException $e) {
            // The same new key arrived twice at once and the other request
            // recorded first. Both executed, which the key could not prevent
            // - it can only stop retries, not simultaneous originals - and
            // this one still has its own valid response to give.
            $this->logger->info('Idempotency record already written by a concurrent request', ['id' => $record->id(), 'exception' => $e]);
        }
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
