<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

final class AddCartProductControllerTest extends ApiTestCase
{
    public function test_add_cart_product_by_id(): void
    {
        $inCart = $this->persistProduct('Already there');
        $cart = $this->persistCart(CartStatus::PENDING, $inCart);
        $product = $this->persistProduct('New one', stock: 3);

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => $cart->id()->toString(),
            'productId' => $product->id()->toString(),
        ]));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();

        self::assertSame($cart->id()->toString(), $body['id']);
        self::assertCount(2, $body['items']);
        self::assertSame(2, $this->stockOf($product), 'one unit was reserved');
    }

    public function test_an_unknown_cart_is_a_404_problem(): void
    {
        $product = $this->persistProduct();

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => Uuid::uuid4()->toString(),
            'productId' => $product->id()->toString(),
        ]));

        $this->assertProblem(404, 'Cart');
        self::assertSame(5, $this->stockOf($product), 'nothing was reserved');
    }

    public function test_an_unknown_product_is_a_404_problem(): void
    {
        $cart = $this->persistCart();

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => $cart->id()->toString(),
            'productId' => Uuid::uuid4()->toString(),
        ]));

        $this->assertProblem(404, 'Product');
    }

    /**
     * Adding to a paid cart reserved a unit that could never be released: the
     * removal path refuses to return stock for a cart that is not pending. Every
     * such request destroyed one unit of inventory.
     */
    public function test_adding_to_a_paid_cart_is_a_409_problem_and_reserves_nothing(): void
    {
        $cart = $this->persistCart(CartStatus::PAID);
        $product = $this->persistProduct(stock: 3);

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => $cart->id()->toString(),
            'productId' => $product->id()->toString(),
        ]));

        $this->assertProblem(409, 'not pending');
        self::assertSame(3, $this->stockOf($product));
        self::assertCount(0, $this->reloadCart($cart)->items());
    }

    public function test_a_product_out_of_stock_is_a_409_problem(): void
    {
        $cart = $this->persistCart();
        $product = $this->persistProduct(stock: 0);

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => $cart->id()->toString(),
            'productId' => $product->id()->toString(),
        ]));

        $this->assertProblem(409, 'out of stock');
        self::assertCount(0, $this->reloadCart($cart)->items());
    }

    public function test_a_malformed_cart_id_is_a_404_problem(): void
    {
        $this->request('PUT', '/api/v1/carts/nope/products/' . Uuid::uuid4()->toString() . '/add');

        $this->assertProblem(404);
    }
}
