<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Product;

use Ramsey\Uuid\Uuid;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * PATCH /v1/products/{id}: name, code and price.
 */
final class PatchProductControllerTest extends ApiTestCase
{
    public function test_it_updates_the_fields_sent_and_answers_the_product(): void
    {
        $product = $this->persistProduct('Gafas', 'K3', '129.95', stock: 12);

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), [
            'name' => 'Gafas Siroko K3',
            'price' => ['amount' => '99.00', 'currency' => 'EUR'],
        ]);

        self::assertResponseStatusCodeSame(200);
        $updated = $this->json();
        self::assertSame([
            'id' => $product->id()->toString(),
            'name' => 'Gafas Siroko K3',
            'code' => 'K3',
            'price' => "99,00\u{a0}€",
            'quantity' => 12,
        ], $updated);

        $this->request('GET', $this->url('api_get_product_by_id', ['id' => $product->id()->toString()]));
        self::assertSame($updated, $this->json(), 'what was updated is what is read back');
    }

    public function test_the_code_can_change_to_a_free_one(): void
    {
        $product = $this->persistProduct(code: 'OLD');

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), ['code' => 'NEW']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('NEW', $this->json()['code']);
    }

    public function test_a_code_taken_by_another_product_is_a_409_problem(): void
    {
        $this->persistProduct(code: 'TAKEN');
        $product = $this->persistProduct(code: 'MINE');

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), ['code' => 'TAKEN']);

        $this->assertProblem(409, 'already exists');
    }

    public function test_a_line_in_a_cart_follows_the_new_price(): void
    {
        $product = $this->persistProduct(amount: '10.00');
        $cart = $this->persistCartWithLines(1, [[$product, 2]]);

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), ['price' => ['amount' => '12.00', 'currency' => 'EUR']]);
        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart->id()->toString()]));

        self::assertSame(['amount' => '24.00', 'currency' => 'EUR'], $this->json()['subtotal'], 'a pending cart is priced at today\'s prices');
    }

    public function test_an_empty_update_is_a_400_problem(): void
    {
        $product = $this->persistProduct();

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), ['quantity' => 3]);

        $this->assertProblem(400, 'at least one');
    }

    public function test_half_a_price_is_a_400_problem(): void
    {
        $product = $this->persistProduct();

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), ['price' => ['amount' => '1.00']]);
        $this->assertProblem(400, '"amount" and "currency"');

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), ['price' => '1.00']);
        $this->assertProblem(400, '"amount" and "currency"');
    }

    public function test_invalid_values_are_400_problems(): void
    {
        $product = $this->persistProduct();

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), ['name' => 'ab']);
        $this->assertProblem(400, 'between 3 and 200');

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), ['price' => ['amount' => '1.999', 'currency' => 'EUR']]);
        $this->assertProblem(400, 'more decimals');

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), ['name' => ['x']]);
        $this->assertProblem(400, '"name" must be a string');
    }

    public function test_an_unknown_product_is_a_404_problem(): void
    {
        $this->request('PATCH', $this->url('api_update_product', ['id' => Uuid::uuid4()->toString()]), ['name' => 'Whatever']);

        $this->assertProblem(404, 'Product');
    }

    public function test_a_withdrawn_product_is_a_404_problem(): void
    {
        $product = $this->persistProduct();
        $this->request('DELETE', $this->url('api_delete_product', ['id' => $product->id()->toString()]));

        $this->request('PATCH', $this->url('api_update_product', ['id' => $product->id()->toString()]), ['name' => 'Whatever']);

        $this->assertProblem(404, 'Product');
    }
}
