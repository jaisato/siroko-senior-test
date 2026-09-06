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
        private ?int $responseStatus,
        private ?string $responseContentType,
        private ?string $responseBody,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $expiresAt,
    ) {}

    /**
     * A record for a key whose request is about to run: it names the request
     * but has no answer yet. Written *before* the work, so that a second
     * request presenting the same key finds it there and does not run the
     * work a second time.
     *
     * @param string $id          what identifies the key within its scope (see IdempotencyGuard::recordId())
     * @param string $scope       the customer the key belongs to, or '' when the API runs unauthenticated
     * @param string $fingerprint what the request looked like, so a reuse with another payload is detected
     */
    public static function claim(string $id, string $scope, string $requestKey, string $fingerprint, \DateTimeImmutable $now, \DateInterval $ttl): self
    {
        return new self($id, $scope, $requestKey, $fingerprint, null, null, null, $now, $now->add($ttl));
    }

    /**
     * Rebuilds a record from what the store kept of it. The response is null
     * for a claim whose request has not finished (or never did).
     */
    public static function restore(string $id, string $scope, string $requestKey, string $fingerprint, ?int $responseStatus, ?string $responseContentType, ?string $responseBody, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): self
    {
        return new self($id, $scope, $requestKey, $fingerprint, $responseStatus, $responseContentType, $responseBody, $createdAt, $expiresAt);
    }

    /**
     * The same record with the answer its request produced, and the lifetime
     * of that answer counted from now.
     */
    public function completedWith(Response $response, \DateTimeImmutable $now, \DateInterval $ttl): self
    {
        return new self(
            $this->id,
            $this->scope,
            $this->requestKey,
            $this->fingerprint,
            $response->getStatusCode(),
            (string) ($response->headers->get('Content-Type') ?? 'application/json'),
            (string) $response->getContent(),
            $this->createdAt,
            $now->add($ttl),
        );
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

    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    public function responseContentType(): ?string
    {
        return $this->responseContentType;
    }

    public function responseBody(): ?string
    {
        return $this->responseBody;
    }

    /** Claimed, but its request has not produced an answer (yet, or ever). */
    public function isPending(): bool
    {
        return null === $this->responseStatus;
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
     *
     * @throws \LogicException on a record that is still pending; the guard
     *                         answers those with a 409 instead
     */
    public function replay(): Response
    {
        if (null === $this->responseStatus || null === $this->responseContentType || null === $this->responseBody) {
            throw new \LogicException(\sprintf('Idempotency record "%s" has no response to replay.', $this->id));
        }

        return new Response($this->responseBody, $this->responseStatus, [
            'Content-Type' => $this->responseContentType,
            self::REPLAYED_HEADER => 'true',
        ]);
    }
}
