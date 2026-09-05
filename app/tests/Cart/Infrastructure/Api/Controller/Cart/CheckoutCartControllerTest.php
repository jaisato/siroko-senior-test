<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

final class CheckoutCartControllerTest extends ApiTestCase
{
    public function test_cart_checkout_by_id(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct());

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        $paid = $this->json();

        self::assertSame($cart->id()->toString(), $paid['id']);
        self::assertSame(CartStatus::PAID, $paid['status']);
        self::assertCount(1, $paid['items'], 'the lines are still there');
        self::assertSame(CartStatus::PAID, $this->reloadCart($cart)->status()->toInt());
    }

    /** Paying twice is the client's conflict, not a server failure. */
    public function test_a_second_checkout_is_a_409_problem(): void
    {
        $cart = $this->persistCart(CartStatus::PAID);

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));

        $this->assertProblem(409, 'not pending');
    }

    public function test_an_unknown_cart_is_a_404_problem(): void
    {
        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => Uuid::uuid4()->toString()]));

        $this->assertProblem(404, 'Cart');
    }
}
