<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api;

use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyGuard;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyRecord;
use Siroko\Cart\Infrastructure\Api\Security\ApiTokenAuthenticator;

/**
 * The CORS policy has to know every header the API accepts and every header
 * a client is meant to read. `X-API-Key` and `Idempotency-Key` arrived with
 * the authentication and idempotency features but were never added to it, so
 * a browser's preflight for either was refused: two features a JavaScript
 * client could not use at all.
 */
final class CorsTest extends ApiTestCase
{
    private const ORIGIN = 'http://localhost:3000';

    public function test_a_preflight_may_announce_the_api_key_and_idempotency_key_headers(): void
    {
        $this->client->request('OPTIONS', '/api/v1/carts', server: [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, authorization, x-api-key, idempotency-key',
        ]);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(self::ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));

        $allowed = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        self::assertStringContainsString(strtolower(ApiTokenAuthenticator::HEADER), $allowed);
        self::assertStringContainsString(strtolower(IdempotencyGuard::HEADER), $allowed);
        self::assertStringContainsString('authorization', $allowed);
        self::assertStringContainsString('content-type', $allowed);
    }

    /** The replay marker is only useful if a browser is allowed to read it. */
    public function test_the_replay_marker_is_exposed_to_browsers(): void
    {
        $this->request('GET', $this->url('api_list_carts'), server: ['HTTP_ORIGIN' => self::ORIGIN]);

        self::assertResponseStatusCodeSame(200);
        $exposed = strtolower((string) $this->client->getResponse()->headers->get('Access-Control-Expose-Headers'));
        self::assertStringContainsString(strtolower(IdempotencyRecord::REPLAYED_HEADER), $exposed);
    }

    public function test_a_header_the_api_does_not_use_is_still_refused(): void
    {
        $this->client->request('OPTIONS', '/api/v1/carts', server: [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-forwarded-secret',
        ]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }
}
