<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Idempotency;

use Psr\Clock\ClockInterface;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\Security\CurrentCustomer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Makes a write safe to retry: a request that carries an `Idempotency-Key`
 * is executed once and its response stored; presenting the same key again
 * with the same request replays that response instead of running the write a
 * second time.
 *
 * A client that lost the answer to "create this cart" - a timeout, a dropped
 * connection - used to have two choices: retry and create a second cart that
 * reserves stock nobody meant to reserve, or not retry and not know whether
 * the first one happened. With a key it retries safely and gets the original
 * answer back, marked `Idempotent-Replayed: true`.
 *
 * The key is scoped to the caller (when the API authenticates), so two
 * customers may use the same key without seeing each other's responses. A
 * key reused with a different request is refused with 422: it is almost
 * certainly a client bug, and answering with the stored response would hide
 * it. Records expire after IDEMPOTENCY_TTL seconds.
 */
final class IdempotencyGuard
{
    public const HEADER = 'Idempotency-Key';

    public const MAX_KEY_LENGTH = 255;

    private readonly \DateInterval $ttl;

    /**
     * @param int $idempotencyTtlSeconds how long a stored response can be replayed
     */
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly CurrentCustomer $customer,
        private readonly ClockInterface $clock,
        private readonly ApiExceptionMapper $errors,
        int $idempotencyTtlSeconds,
    ) {
        if ($idempotencyTtlSeconds < 1) {
            throw new \InvalidArgumentException(\sprintf('The idempotency TTL must be at least one second, got %d.', $idempotencyTtlSeconds));
        }

        $this->ttl = new \DateInterval(\sprintf('PT%dS', $idempotencyTtlSeconds));
    }

    /**
     * Runs `$produce` unless the request's key already has an answer.
     *
     * Responses of every status below 500 are remembered - a 404 or a 409 is
     * as final as a 201, and replaying it is what the client expects; a 500
     * is not, since the retry may well succeed.
     *
     * @param callable(): Response $produce
     */
    public function respond(Request $request, callable $produce): Response
    {
        $key = $request->headers->get(self::HEADER);

        if (null === $key) {
            return $produce();
        }

        if ('' === trim($key) || \strlen($key) > self::MAX_KEY_LENGTH || 1 !== preg_match('/^[\x21-\x7E]+\z/', $key)) {
            return $this->errors->toResponse(new BadRequestHttpException(\sprintf('The %s header must be 1 to %d printable characters without whitespace.', self::HEADER, self::MAX_KEY_LENGTH)));
        }

        $now = $this->clock->now();
        $scope = $this->customer->idOrNull() ?? '';
        $id = self::recordId($scope, $key);
        $fingerprint = self::fingerprint($request);
        $record = $this->store->find($id);

        if (null !== $record && !$record->isExpiredAt($now)) {
            if (!$record->matches($fingerprint)) {
                return $this->errors->toResponse(new UnprocessableEntityHttpException(\sprintf('The %s header was already used with a different request; use a new key for a new request.', self::HEADER)));
            }

            return $record->replay();
        }

        $response = $produce();

        if ($response->getStatusCode() >= 500) {
            return $response;
        }

        $this->store->save(IdempotencyRecord::capture($id, $scope, $key, $fingerprint, $response, $now, $this->ttl));

        return $response;
    }

    /**
     * The key within its scope, hashed to a fixed length so the primary key
     * is compact whatever the client sends, and so that the stored id does
     * not reveal the key itself.
     */
    public static function recordId(string $scope, string $key): string
    {
        return hash('sha256', $scope . "\n" . $key);
    }

    /**
     * Method, path and body: what makes two requests "the same request".
     * Headers are left out on purpose - a retry through another proxy adds
     * its own, and they say nothing about what was asked.
     */
    public static function fingerprint(Request $request): string
    {
        return hash('sha256', $request->getMethod() . "\n" . $request->getPathInfo() . "\n" . $request->getContent());
    }
}
