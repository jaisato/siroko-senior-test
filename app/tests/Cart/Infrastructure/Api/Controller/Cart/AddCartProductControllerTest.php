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

    /** Adding a product the cart holds grows its line instead of opening a second one. */
    public function test_adding_a_product_already_in_the_cart_grows_its_line(): void
    {
        $product = $this->persistProduct(stock: 5);
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 2]]);

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => $cart->id()->toString(),
            'productId' => $product->id()->toString(),
        ]));

        self::assertResponseStatusCodeSame(200);
        $items = array_values($this->json()['items']);
        self::assertCount(1, $items);
        self::assertSame(3, $items[0]['quantity']);
        self::assertSame(4, $this->stockOf($product));
    }

    public function test_a_body_may_ask_for_several_units_at_once(): void
    {
        $cart = $this->persistCart();
        $product = $this->persistProduct(stock: 5);

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => $cart->id()->toString(),
            'productId' => $product->id()->toString(),
        ]), ['quantity' => 4]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, array_values($this->json()['items'])[0]['quantity']);
        self::assertSame(1, $this->stockOf($product));
    }

    public function test_asking_for_more_units_than_the_stock_is_a_409_problem_and_reserves_nothing(): void
    {
        $cart = $this->persistCart();
        $product = $this->persistProduct(stock: 2);

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => $cart->id()->toString(),
            'productId' => $product->id()->toString(),
        ]), ['quantity' => 3]);

        $this->assertProblem(409, 'out of stock');
        self::assertSame(2, $this->stockOf($product));
    }

    public function test_a_quantity_a_line_cannot_hold_is_a_400_problem(): void
    {
        $cart = $this->persistCart();
        $product = $this->persistProduct(stock: 5);

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => $cart->id()->toString(),
            'productId' => $product->id()->toString(),
        ]), ['quantity' => 0]);

        $this->assertProblem(400, 'greater or equal to 1');

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => $cart->id()->toString(),
            'productId' => $product->id()->toString(),
        ]), ['quantity' => 'two']);

        $this->assertProblem(400, 'integer');
        self::assertSame(5, $this->stockOf($product));
    }

    /** A cart is paid in one currency; a product in another has no place in its total. */
    public function test_a_product_in_another_currency_is_a_409_problem_and_reserves_nothing(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct('Euros', currency: 'EUR'));
        $dollars = $this->persistProduct('Dollars', currency: 'USD', stock: 3);

        $this->request('PUT', $this->url('api_add_cart_product_by_id', [
            'cartId' => $cart->id()->toString(),
            'productId' => $dollars->id()->toString(),
        ]));

        $this->assertProblem(409, 'USD');
        self::assertSame(3, $this->stockOf($dollars));
        self::assertCount(1, $this->reloadCart($cart)->items());
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
