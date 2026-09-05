<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * The endpoint had no functional test, and answered 204 to everything: an
 * unknown cart, an unknown item, an item of somebody else's cart.
 */
final class DeleteCartItemControllerTest extends ApiTestCase
{
    public function test_removing_an_item_answers_204_with_no_body_and_returns_the_unit(): void
    {
        $product = $this->persistProduct(stock: 4);
        $cart = $this->persistCart(CartStatus::PENDING, $product, $this->persistProduct('Other'));
        $item = $this->firstItem($cart);

        $response = $this->request('DELETE', $this->url('api_delete_cart_item_by_id', [
            'cartId' => $cart->id()->toString(),
            'itemId' => $item->id()->toString(),
        ]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent(), 'a 204 carries no body');
        self::assertSame(5, $this->stockOf($product), 'the reserved unit went back to the shelf');
        self::assertCount(1, $this->reloadCart($cart)->items());
    }

    public function test_the_route_names_the_items_sub_collection(): void
    {
        $url = $this->url('api_delete_cart_item_by_id', ['cartId' => Uuid::uuid4()->toString(), 'itemId' => Uuid::uuid4()->toString()]);

        self::assertMatchesRegularExpression('#^/api/v1/carts/[0-9a-f-]{36}/items/[0-9a-f-]{36}$#', $url);
    }

    public function test_an_unknown_cart_is_a_404_problem(): void
    {
        $this->request('DELETE', $this->url('api_delete_cart_item_by_id', [
            'cartId' => Uuid::uuid4()->toString(),
            'itemId' => Uuid::uuid4()->toString(),
        ]));

        $this->assertProblem(404, 'Cart');
    }

    public function test_an_unknown_item_is_a_404_problem(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct());

        $this->request('DELETE', $this->url('api_delete_cart_item_by_id', [
            'cartId' => $cart->id()->toString(),
            'itemId' => Uuid::uuid4()->toString(),
        ]));

        $this->assertProblem(404, 'Item');
        self::assertCount(1, $this->reloadCart($cart)->items());
    }

    /**
     * A delete aimed at a cart that does not own the item must not credit any
     * stock: a repeated or mistargeted request would otherwise mint inventory.
     */
    public function test_an_item_of_another_cart_is_a_404_problem_and_credits_nothing(): void
    {
        $product = $this->persistProduct(stock: 4);
        $owner = $this->persistCart(CartStatus::PENDING, $product);
        $other = $this->persistCart(CartStatus::PENDING);
        $item = $this->firstItem($owner);

        $this->request('DELETE', $this->url('api_delete_cart_item_by_id', [
            'cartId' => $other->id()->toString(),
            'itemId' => $item->id()->toString(),
        ]));

        $this->assertProblem(404, 'Item');
        self::assertSame(4, $this->stockOf($product));
        self::assertCount(1, $this->reloadCart($owner)->items(), 'the owner keeps its line');
    }

    /** Once paid, the unit is sold rather than reserved, and the line is not ours to remove. */
    public function test_removing_from_a_paid_cart_is_a_409_problem_and_credits_nothing(): void
    {
        $product = $this->persistProduct(stock: 4);
        $cart = $this->persistCart(CartStatus::PAID, $product);
        $item = $this->firstItem($cart);

        $this->request('DELETE', $this->url('api_delete_cart_item_by_id', [
            'cartId' => $cart->id()->toString(),
            'itemId' => $item->id()->toString(),
        ]));

        $this->assertProblem(409, 'not pending');
        self::assertSame(4, $this->stockOf($product));
        self::assertCount(1, $this->reloadCart($cart)->items());
    }

    public function test_a_malformed_item_id_is_a_404_problem(): void
    {
        $this->request('DELETE', '/api/v1/carts/' . Uuid::uuid4()->toString() . '/items/nope');

        $this->assertProblem(404);
    }

    private function firstItem(Cart $cart): CartItem
    {
        $item = $cart->items()->first();
        self::assertInstanceOf(CartItem::class, $item);

        return $item;
    }
}
