<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\CreateCartCommand;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

final class PostCartControllerTest extends ApiTestCase
{
    public function test_create_cart(): void
    {
        $first = $this->persistProduct('First', stock: 5);
        $second = $this->persistProduct('Second', stock: 5);

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [
                ['productId' => $first->id()->toString(), 'quantity' => 2],
                ['productId' => $second->id()->toString(), 'quantity' => 1],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $cart = $this->json();

        self::assertTrue(Uuid::isValid($cart['id']));
        self::assertSame(CartStatus::PENDING, $cart['status']);
        self::assertCount(3, $cart['items'], 'one line per unit');

        foreach ($cart['items'] as $item) {
            self::assertArrayHasKey('id', $item);
            self::assertArrayHasKey('name', $item);
            self::assertArrayHasKey('code', $item);
            self::assertArrayHasKey('price', $item);
        }

        self::assertSame(3, $this->stockOf($first), 'two units were reserved');
        self::assertSame(4, $this->stockOf($second));
    }

    public function test_an_empty_body_is_a_400_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), '');

        $this->assertProblem(400, 'JSON body is required');
    }

    public function test_a_body_that_is_not_a_json_object_is_a_400_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), '[1, 2, 3]');

        $this->assertProblem(400, 'not a valid JSON object');
    }

    public function test_a_body_without_products_is_a_400_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), ['items' => []]);

        $this->assertProblem(400, '"products" is required');
    }

    public function test_products_must_be_a_list(): void
    {
        $this->request('POST', $this->url('api_create_cart'), [
            'products' => ['productId' => Uuid::uuid4()->toString(), 'quantity' => 1],
        ]);

        $this->assertProblem(400, 'must be a list');
    }

    /**
     * Body identifiers are validated by the value object rather than the
     * router, so here a malformed UUID is a 400, not a 404.
     */
    public function test_a_malformed_product_id_is_a_400_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [['productId' => 'not-a-uuid', 'quantity' => 1]],
        ]);

        $this->assertProblem(400, 'UUID');
    }

    public function test_a_line_with_zero_units_is_a_400_problem(): void
    {
        $product = $this->persistProduct();

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [['productId' => $product->id()->toString(), 'quantity' => 0]],
        ]);

        $this->assertProblem(400, 'greater or equal to 1');
        self::assertSame(5, $this->stockOf($product), 'nothing was reserved');
    }

    public function test_a_non_integer_quantity_is_a_400_problem(): void
    {
        $product = $this->persistProduct();

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [['productId' => $product->id()->toString(), 'quantity' => 'two']],
        ]);

        $this->assertProblem(400, 'integer');
    }

    public function test_too_many_lines_is_a_400_problem(): void
    {
        $lines = [];
        for ($i = 0; $i <= CreateCartCommand::MAX_LINES; ++$i) {
            $lines[] = ['productId' => Uuid::uuid4()->toString(), 'quantity' => 1];
        }

        $this->request('POST', $this->url('api_create_cart'), ['products' => $lines]);

        $this->assertProblem(400, 'at most');
    }

    public function test_an_unknown_product_is_a_404_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [['productId' => Uuid::uuid4()->toString(), 'quantity' => 1]],
        ]);

        $this->assertProblem(404, 'Product');
    }

    public function test_a_product_without_enough_stock_is_a_409_problem_and_nothing_is_reserved(): void
    {
        $available = $this->persistProduct('Available', stock: 5);
        $scarce = $this->persistProduct('Scarce', stock: 1);

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [
                ['productId' => $available->id()->toString(), 'quantity' => 1],
                ['productId' => $scarce->id()->toString(), 'quantity' => 2],
            ],
        ]);

        $this->assertProblem(409, 'units available');
        self::assertSame(5, $this->stockOf($available), 'the whole request rolled back');
        self::assertSame(1, $this->stockOf($scarce));
    }
}
