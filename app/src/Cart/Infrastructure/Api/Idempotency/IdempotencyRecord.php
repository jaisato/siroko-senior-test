<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Idempotency;

use Symfony\Component\HttpFoundation\Response;

/**
 * What one Idempotency-Key produced, kept so the same request can be
 * answered again without being executed again.
 *
 * An infrastructure entity: it is about HTTP, not about carts. The ORM maps
 * it so that its table is created with the rest of the schema and checked by
 * schema:validate like every other one; the store, though, reads and writes
 * it through the connection directly (see DoctrineIdempotencyStore for why).
 */
class IdempotencyRecord
{
    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    private function __construct(
        private string $id,
        private string $scope,
        private string $requestKey,
        private string $fingerprint,
        private int $responseStatus,
        private string $responseContentType,
        private string $responseBody,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $expiresAt,
    ) {}

    /**
     * Records a response that has just been produced.
     *
     * @param string $id          what identifies the key within its scope (see IdempotencyGuard::recordId())
     * @param string $scope       the customer the key belongs to, or '' when the API runs unauthenticated
     * @param string $fingerprint what the request looked like, so a reuse with another payload is detected
     */
    public static function capture(string $id, string $scope, string $requestKey, string $fingerprint, Response $response, \DateTimeImmutable $now, \DateInterval $ttl): self
    {
        return new self(
            $id,
            $scope,
            $requestKey,
            $fingerprint,
            $response->getStatusCode(),
            (string) ($response->headers->get('Content-Type') ?? 'application/json'),
            (string) $response->getContent(),
            $now,
            $now->add($ttl),
        );
    }

    /**
     * Rebuilds a record from what the store kept of it.
     */
    public static function restore(string $id, string $scope, string $requestKey, string $fingerprint, int $responseStatus, string $responseContentType, string $responseBody, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): self
    {
        return new self($id, $scope, $requestKey, $fingerprint, $responseStatus, $responseContentType, $responseBody, $createdAt, $expiresAt);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function requestKey(): string
    {
        return $this->requestKey;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function responseStatus(): int
    {
        return $this->responseStatus;
    }

    public function responseContentType(): string
    {
        return $this->responseContentType;
    }

    public function responseBody(): string
    {
        return $this->responseBody;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function matches(string $fingerprint): bool
    {
        return hash_equals($this->fingerprint, $fingerprint);
    }

    /**
     * The stored response, marked as a replay so the client can tell.
     */
    public function replay(): Response
    {
        return new Response($this->responseBody, $this->responseStatus, [
            'Content-Type' => $this->responseContentType,
            self::REPLAYED_HEADER => 'true',
        ]);
    }
}
