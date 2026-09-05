<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * GET /v1/products/by-code/{code}.
 */
final class GetProductByCodeControllerTest extends ApiTestCase
{
    public function test_get_product_by_code(): void
    {
        $product = $this->persistProduct('Gafas Siroko', 'K3-BLACK', '129.95', 'EUR', 12);

        $this->request('GET', $this->url('api_get_product_by_code', ['code' => 'K3-BLACK']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'id' => $product->id()->toString(),
            'name' => 'Gafas Siroko',
            'code' => 'K3-BLACK',
            'price' => "129,95\u{a0}€",
            'quantity' => 12,
        ], $this->json());
    }

    public function test_codes_with_dots_and_spaces_are_reachable(): void
    {
        $product = $this->persistProduct(code: 'K3.BLACK v2');

        $this->request('GET', '/api/v1/products/by-code/' . rawurlencode('K3.BLACK v2'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($product->id()->toString(), $this->json()['id']);
    }

    public function test_an_unknown_code_is_a_404_problem(): void
    {
        $this->request('GET', $this->url('api_get_product_by_code', ['code' => 'NOPE']));

        $this->assertProblem(404, 'NOPE');
    }

    /** `by-code` is not a UUID, so it never collides with GET /v1/products/{id}. */
    public function test_the_route_does_not_shadow_the_id_route(): void
    {
        $product = $this->persistProduct(code: 'K3');

        $this->request('GET', $this->url('api_get_product_by_id', ['id' => $product->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('K3', $this->json()['code']);
    }

    public function test_a_code_longer_than_fifty_characters_is_a_404_problem(): void
    {
        $this->request('GET', '/api/v1/products/by-code/' . str_repeat('x', 51));

        $this->assertProblem(404);
    }
}
