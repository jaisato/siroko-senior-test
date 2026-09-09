<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\OpenApi;

use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;

/**
 * The one error shape of the API, as OpenAPI describes it.
 *
 * ApiExceptionMapper answers every error - a rejected request, a missing
 * resource, a conflict with the state of a cart, an unexpected failure - with
 * the same four RFC 7807 members. Each resource references this schema from
 * every error response it declares, so the documentation promises exactly
 * what the mapper sends and a client handles every error with one type.
 *
 * Attributes only take constant expressions, which is why the pieces the
 * resources need are constants rather than a factory method.
 */
final class Problem
{
    public const SCHEMA_NAME = 'Problem';

    public const REF = '#/components/schemas/' . self::SCHEMA_NAME;

    /**
     * The `content` of every error response: the problem media type, this schema.
     *
     * @var array<string, array{schema: array{'$ref': string}}>
     */
    public const CONTENT = [
        ApiExceptionMapper::CONTENT_TYPE => ['schema' => ['$ref' => self::REF]],
    ];

    /** Descriptions of the responses that several operations answer alike. */
    public const MALFORMED_IDEMPOTENCY_KEY = 'the Idempotency-Key header is not 1 to 255 printable characters without whitespace';

    public const IDEMPOTENCY_KEY_REUSED = 'The Idempotency-Key header was already used with a different method, path or body; a new request needs a new key.';

    /**
     * The one 409 that is about the key rather than the cart. It shares the
     * status with "not pending" and "out of stock", and the remedy is not the
     * same: the original request is still running (retry in a moment), or it
     * died without recording its outcome (check whether it took effect, then
     * use a new key - the same one is refused for the rest of its TTL).
     */
    public const IDEMPOTENCY_KEY_HELD = 'the Idempotency-Key header names a request that is still being processed (retry in a moment), or one that died without reporting its outcome and is refused under that key for the rest of its TTL (check whether it took effect, then use a new key)';

    public const UNAUTHENTICATED = 'Authentication is on (API_TOKENS is set) and the request carried no valid token, neither as "Authorization: Bearer <token>" nor as "X-API-Key: <token>".';

    /**
     * @var array<string, mixed>
     */
    public const SCHEMA = [
        'type' => 'object',
        'description' => 'RFC 7807 problem details: the shape of every error the API answers, with Content-Type application/problem+json.',
        'required' => ['type', 'title', 'status', 'detail'],
        'additionalProperties' => false,
        'properties' => [
            'type' => [
                'type' => 'string',
                'format' => 'uri',
                'description' => 'Identifies the problem type; "about:blank" when the status code says it all.',
                'examples' => ['about:blank'],
            ],
            'title' => [
                'type' => 'string',
                'description' => 'The reason phrase of the status code.',
                'examples' => ['Conflict'],
            ],
            'status' => [
                'type' => 'integer',
                'minimum' => 400,
                'maximum' => 599,
                'description' => 'The HTTP status code, repeated in the body.',
                'examples' => [409],
            ],
            'detail' => [
                'type' => 'string',
                'description' => 'What went wrong with this request, written for the caller. For an unexpected failure (500) it is deliberately generic: the cause is logged, never returned.',
                'examples' => ['Cart is not pending'],
            ],
        ],
    ];
}
