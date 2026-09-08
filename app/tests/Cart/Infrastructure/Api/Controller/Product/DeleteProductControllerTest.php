<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Product;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * DELETE /v1/products/{id}: withdraw from the catalogue, keep the row.
 */
final class DeleteProductControllerTest extends ApiTestCase
{
    public function test_a_withdrawn_product_answers_204_and_is_no_longer_found(): void
    {
        $product = $this->persistProduct('Gafas', 'K3');

        $response = $this->request('DELETE', $this->url('api_delete_product', ['id' => $product->id()->toString()]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());

        $this->request('GET', $this->url('api_get_product_by_id', ['id' => $product->id()->toString()]));
        $this->assertProblem(404, 'Product');

        $this->request('GET', $this->url('api_get_product_by_code', ['code' => 'K3']));
        $this->assertProblem(404, 'Product');

        $this->request('GET', $this->url('api_get_products'));
        self::assertSame(0, $this->json()['total'], 'not listed either');

        $this->em()->clear();
        $row = $this->em()->find(Product::class, $product->id());
        self::assertInstanceOf(Product::class, $row, 'the row stays');
        self::assertTrue($row->isDeleted());
    }

    public function test_a_second_delete_is_a_404_problem(): void
    {
        $product = $this->persistProduct();
        $this->request('DELETE', $this->url('api_delete_product', ['id' => $product->id()->toString()]));

        $this->request('DELETE', $this->url('api_delete_product', ['id' => $product->id()->toString()]));

        $this->assertProblem(404, 'Product');
    }

    public function test_a_withdrawn_product_can_no_longer_be_added_to_a_cart(): void
    {
        $product = $this->persistProduct(stock: 5);
        $cart = $this->persistCart();
        $this->request('DELETE', $this->url('api_delete_product', ['id' => $product->id()->toString()]));

        $this->request('PUT', $this->url('api_add_cart_product_by_id', ['cartId' => $cart->id()->toString(), 'productId' => $product->id()->toString()]));
        $this->assertProblem(404, 'Product');

        $this->request('POST', $this->url('api_create_cart'), ['products' => [['productId' => $product->id()->toString(), 'quantity' => 1]]]);
        $this->assertProblem(404, 'Product');

        self::assertSame(5, $this->stockOf($product));
    }

    /** Lines that already hold the product keep it, and the cart can still be paid for. */
    public function test_carts_holding_a_withdrawn_product_keep_their_lines(): void
    {
        $product = $this->persistProduct('Gafas', stock: 5);
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 2]]);

        $this->request('DELETE', $this->url('api_delete_product', ['id' => $product->id()->toString()]));

        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart->id()->toString()]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Gafas', $this->json()['items'][0]['name']);

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Gafas', $this->json()['order']['lines'][0]['name']);
    }

    /** A code stays taken: the unique index is on the code alone, and a withdrawn row keeps it. */
    public function test_the_code_of_a_withdrawn_product_stays_taken(): void
    {
        $product = $this->persistProduct(code: 'K3');
        $this->request('DELETE', $this->url('api_delete_product', ['id' => $product->id()->toString()]));

        $this->request('POST', $this->url('api_create_product'), [
            'name' => 'New K3', 'code' => 'K3', 'priceAmount' => '1.00', 'priceCurrency' => 'EUR', 'quantity' => 1,
        ]);

        $this->assertProblem(409, 'already exists');
    }

    public function test_an_unknown_product_is_a_404_problem(): void
    {
        $this->request('DELETE', $this->url('api_delete_product', ['id' => Uuid::uuid4()->toString()]));

        $this->assertProblem(404, 'Product');
    }

    public function test_a_malformed_id_is_a_404_problem(): void
    {
        $this->request('DELETE', '/api/v1/products/nope');

        $this->assertProblem(404);
    }
}
