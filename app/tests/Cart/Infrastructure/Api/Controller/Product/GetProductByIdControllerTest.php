<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Product;

use Ramsey\Uuid\Uuid;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

final class GetProductByIdControllerTest extends ApiTestCase
{
    /**
     * Broken until now: the read model called `Money::formatTo()`, which does
     * not exist in the pinned brick/money release, so every product read was
     * a 500.
     */
    public function test_get_product_by_id(): void
    {
        $product = $this->persistProduct('Gafas Siroko', 'K3-BLACK', '129.95', 'EUR', 12);

        $this->request('GET', $this->url('api_get_product_by_id', ['id' => $product->id()->toString()]));

        self::assertResponseStatusCodeSame(200);

        self::assertSame([
            'id' => $product->id()->toString(),
            'name' => 'Gafas Siroko',
            'code' => 'K3-BLACK',
            'price' => "129,95\u{a0}€",
            'quantity' => 12,
        ], $this->json());
    }

    public function test_an_unknown_product_is_a_404_problem(): void
    {
        $this->request('GET', $this->url('api_get_product_by_id', ['id' => Uuid::uuid4()->toString()]));

        $this->assertProblem(404, 'Product');
    }

    public function test_a_malformed_id_is_a_404_problem(): void
    {
        $this->request('GET', '/api/v1/products/123');

        $this->assertProblem(404);
    }
}
