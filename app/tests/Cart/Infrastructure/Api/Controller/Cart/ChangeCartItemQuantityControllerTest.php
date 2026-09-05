<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * PATCH /v1/carts/{cartId}/items/{itemId} with {"quantity": n}.
 */
final class ChangeCartItemQuantityControllerTest extends ApiTestCase
{
    public function test_growing_a_line_reserves_the_difference(): void
    {
        $product = $this->persistProduct(stock: 10);
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 2]]);
        $item = $this->firstItem($cart);

        $this->request('PATCH', $this->patchUrl($cart, $item), ['quantity' => 5]);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame($cart->id()->toString(), $body['id']);
        self::assertSame(5, array_values($body['items'])[0]['quantity']);
        self::assertSame(7, $this->stockOf($product), 'three more units were reserved');
    }

    public function test_shrinking_a_line_returns_the_difference(): void
    {
        $product = $this->persistProduct(stock: 10);
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 5]]);
        $item = $this->firstItem($cart);

        $this->request('PATCH', $this->patchUrl($cart, $item), ['quantity' => 2]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, array_values($this->json()['items'])[0]['quantity']);
        self::assertSame(13, $this->stockOf($product), 'three units went back');
    }

    public function test_zero_removes_the_line_and_answers_the_cart_without_it(): void
    {
        $product = $this->persistProduct(stock: 10);
        $other = $this->persistProduct('Other');
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 4], [$other, 1]]);
        $item = $this->lineFor($cart, $product->id()->toString());

        $this->request('PATCH', $this->patchUrl($cart, $item), ['quantity' => 0]);

        self::assertResponseStatusCodeSame(200);
        $items = array_values($this->json()['items']);
        self::assertCount(1, $items);
        self::assertSame($other->id()->toString(), $items[0]['productId']);
        self::assertSame(14, $this->stockOf($product), 'all four units went back');
        self::assertCount(1, $this->reloadCart($cart)->items());
    }

    public function test_more_units_than_the_stock_is_a_409_problem_and_nothing_moves(): void
    {
        $product = $this->persistProduct(stock: 2);
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]]);
        $item = $this->firstItem($cart);

        $this->request('PATCH', $this->patchUrl($cart, $item), ['quantity' => 4]);

        $this->assertProblem(409, 'units available');
        self::assertSame(2, $this->stockOf($product));
        self::assertSame(1, $this->firstItem($this->reloadCart($cart))->quantity()->asInt());
    }

    public function test_a_quantity_that_is_not_one_a_line_holds_is_a_400_problem(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct());
        $item = $this->firstItem($cart);

        $this->request('PATCH', $this->patchUrl($cart, $item), ['quantity' => -1]);
        $this->assertProblem(400, 'greater or equal to 0');

        $this->request('PATCH', $this->patchUrl($cart, $item), ['quantity' => CartItem::MAX_QUANTITY + 1]);
        $this->assertProblem(400, 'lower or equal to');

        $this->request('PATCH', $this->patchUrl($cart, $item), ['quantity' => 'three']);
        $this->assertProblem(400, 'integer');

        $this->request('PATCH', $this->patchUrl($cart, $item), ['units' => 3]);
        $this->assertProblem(400, '"quantity" is required');

        $this->request('PATCH', $this->patchUrl($cart, $item), '');
        $this->assertProblem(400, 'JSON body is required');
    }

    public function test_a_paid_cart_is_a_409_problem(): void
    {
        $product = $this->persistProduct(stock: 5);
        $cart = $this->persistCartWithLines(CartStatus::PAID, [[$product, 1]]);
        $item = $this->firstItem($cart);

        $this->request('PATCH', $this->patchUrl($cart, $item), ['quantity' => 3]);

        $this->assertProblem(409, 'not pending');
        self::assertSame(5, $this->stockOf($product));
    }

    public function test_an_unknown_cart_is_a_404_problem(): void
    {
        $this->request('PATCH', '/api/v1/carts/' . Uuid::uuid4()->toString() . '/items/' . Uuid::uuid4()->toString(), ['quantity' => 1]);

        $this->assertProblem(404, 'Cart');
    }

    public function test_an_unknown_line_is_a_404_problem(): void
    {
        $cart = $this->persistCart();

        $this->request('PATCH', '/api/v1/carts/' . $cart->id()->toString() . '/items/' . Uuid::uuid4()->toString(), ['quantity' => 1]);

        $this->assertProblem(404, 'Item');
    }

    public function test_a_line_of_another_cart_is_a_404_problem_and_nothing_moves(): void
    {
        $product = $this->persistProduct(stock: 5);
        $owner = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]]);
        $other = $this->persistCart();

        $this->request('PATCH', $this->patchUrl($other, $this->firstItem($owner)), ['quantity' => 3]);

        $this->assertProblem(404, 'Item');
        self::assertSame(5, $this->stockOf($product));
    }

    public function test_a_malformed_id_is_a_404_problem(): void
    {
        $this->request('PATCH', '/api/v1/carts/' . Uuid::uuid4()->toString() . '/items/nope', ['quantity' => 1]);

        $this->assertProblem(404);
    }

    private function patchUrl(Cart $cart, CartItem $item): string
    {
        return $this->url('api_change_cart_item_quantity', [
            'cartId' => $cart->id()->toString(),
            'itemId' => $item->id()->toString(),
        ]);
    }

    private function firstItem(Cart $cart): CartItem
    {
        $item = $cart->items()->first();
        self::assertInstanceOf(CartItem::class, $item);

        return $item;
    }

    private function lineFor(Cart $cart, string $productId): CartItem
    {
        foreach ($cart->items() as $item) {
            if ($item->getProduct()->id()->toString() === $productId) {
                return $item;
            }
        }

        self::fail('no line for the product');
    }
}
