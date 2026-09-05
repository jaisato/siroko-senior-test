<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api;

/**
 * The resource classes only carry metadata; this pins that API Platform still
 * documents every endpoint from them.
 */
final class OpenApiDocumentationTest extends ApiTestCase
{
    public function test_every_endpoint_is_documented(): void
    {
        $this->request('GET', '/api/docs.jsonopenapi', server: ['HTTP_ACCEPT' => 'application/vnd.openapi+json']);

        self::assertResponseStatusCodeSame(200);
        $paths = $this->json()['paths'];
        self::assertIsArray($paths);

        self::assertArrayHasKey('get', $paths['/api/v1/carts/{id}']);
        self::assertArrayHasKey('post', $paths['/api/v1/carts']);
        self::assertArrayHasKey('delete', $paths['/api/v1/carts/{cartId}/items/{itemId}']);
        self::assertArrayHasKey('put', $paths['/api/v1/carts/{id}/checkout']);
        self::assertArrayHasKey('put', $paths['/api/v1/carts/{cartId}/products/{productId}/add']);
        self::assertArrayHasKey('get', $paths['/api/v1/products/{id}']);
        self::assertArrayHasKey('get', $paths['/api/v1/products']);
        self::assertArrayHasKey('post', $paths['/api/v1/products']);
    }
}
