<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Order;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * GET /v1/orders/{id}: what the checkout returned, read back later.
 */
final class GetOrderControllerTest extends ApiTestCase
{
    public function test_get_order_by_id(): void
    {
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$this->persistProduct('Gafas', 'K3', '129.95'), 2]]);
        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));
        $placed = $this->json()['order'];

        $this->request('GET', $this->url('api_get_order_by_id', ['id' => $placed['id']]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($placed, $this->json(), 'what the checkout answered is what is read back');
        self::assertSame(['amount' => '259.90', 'currency' => 'EUR'], $this->json()['total']);
        self::assertSame('K3', $this->json()['lines'][0]['code']);
    }

    public function test_an_unknown_order_is_a_404_problem(): void
    {
        $this->request('GET', $this->url('api_get_order_by_id', ['id' => Uuid::uuid4()->toString()]));

        $this->assertProblem(404, 'Order');
    }

    public function test_a_malformed_id_is_a_404_problem(): void
    {
        $this->request('GET', '/api/v1/orders/123');

        $this->assertProblem(404);
    }
}
