<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * DELETE /v1/carts/{id}: cancel the cart and release what it reserved.
 */
final class CancelCartControllerTest extends ApiTestCase
{
    public function test_canceling_a_pending_cart_answers_204_and_returns_every_unit(): void
    {
        $first = $this->persistProduct('First', stock: 2);
        $second = $this->persistProduct('Second', stock: 7);
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$first, 3], [$second, 1]]);

        $response = $this->request('DELETE', $this->url('api_cancel_cart_by_id', ['id' => $cart->id()->toString()]));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame(5, $this->stockOf($first), 'three units went back');
        self::assertSame(8, $this->stockOf($second));

        $canceled = $this->reloadCart($cart);
        self::assertSame(CartStatus::CANCELED, $canceled->status()->toInt());
        self::assertCount(2, $canceled->items(), 'the lines stay as a record');
    }

    public function test_the_canceled_cart_stays_readable_with_its_status(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct());

        $this->request('DELETE', $this->url('api_cancel_cart_by_id', ['id' => $cart->id()->toString()]));
        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(CartStatus::CANCELED, $this->json()['status']);
    }

    public function test_a_paid_cart_can_be_called_off_and_its_units_go_back(): void
    {
        $product = $this->persistProduct(stock: 4);
        $cart = $this->persistCartWithLines(CartStatus::PAID, [[$product, 2]]);

        $this->request('DELETE', $this->url('api_cancel_cart_by_id', ['id' => $cart->id()->toString()]));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(6, $this->stockOf($product));
        self::assertSame(CartStatus::CANCELED, $this->reloadCart($cart)->status()->toInt());
    }

    #[DataProvider('finalStatuses')]
    public function test_a_final_cart_is_a_409_problem_and_nothing_moves(int $status): void
    {
        $product = $this->persistProduct(stock: 4);
        $cart = $this->persistCartWithLines($status, [[$product, 2]]);

        $this->request('DELETE', $this->url('api_cancel_cart_by_id', ['id' => $cart->id()->toString()]));

        $this->assertProblem(409, 'neither pending nor paid');
        self::assertSame(4, $this->stockOf($product));
        self::assertSame($status, $this->reloadCart($cart)->status()->toInt());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function finalStatuses(): iterable
    {
        yield 'delivered' => [CartStatus::DELIVERED];
        yield 'canceled' => [CartStatus::CANCELED];
    }

    /** After a cancellation the cart accepts no more writes. */
    public function test_a_canceled_cart_refuses_further_writes(): void
    {
        $product = $this->persistProduct(stock: 4);
        $cart = $this->persistCart(CartStatus::PENDING, $product);

        $this->request('DELETE', $this->url('api_cancel_cart_by_id', ['id' => $cart->id()->toString()]));
        self::assertResponseStatusCodeSame(204);

        $this->request('PUT', $this->url('api_add_cart_product_by_id', ['cartId' => $cart->id()->toString(), 'productId' => $product->id()->toString()]));
        $this->assertProblem(409, 'not pending');

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));
        $this->assertProblem(409, 'not pending');

        self::assertSame(5, $this->stockOf($product), 'the one unit came back once and stayed back');
    }

    public function test_an_unknown_cart_is_a_404_problem(): void
    {
        $this->request('DELETE', $this->url('api_cancel_cart_by_id', ['id' => Uuid::uuid4()->toString()]));

        $this->assertProblem(404, 'Cart');
    }

    public function test_a_malformed_id_is_a_404_problem(): void
    {
        $this->request('DELETE', '/api/v1/carts/nope');

        $this->assertProblem(404);
    }
}
