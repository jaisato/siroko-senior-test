<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * PUT /v1/carts/{id}/deliver.
 */
final class DeliverCartControllerTest extends ApiTestCase
{
    public function test_a_paid_cart_becomes_delivered(): void
    {
        $cart = $this->persistCart(CartStatus::PAID, $this->persistProduct());

        $this->request('PUT', $this->url('api_cart_deliver_by_id', ['id' => $cart->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(CartStatus::DELIVERED, $this->json()['status']);
        self::assertSame($cart->id()->toString(), $this->json()['id']);
        self::assertSame(CartStatus::DELIVERED, $this->reloadCart($cart)->status()->toInt());
    }

    #[DataProvider('statusesThatCannotBeDelivered')]
    public function test_a_cart_that_is_not_paid_is_a_409_problem(int $status): void
    {
        $cart = $this->persistCart($status, $this->persistProduct());

        $this->request('PUT', $this->url('api_cart_deliver_by_id', ['id' => $cart->id()->toString()]));

        $this->assertProblem(409, 'not paid');
        self::assertSame($status, $this->reloadCart($cart)->status()->toInt());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statusesThatCannotBeDelivered(): iterable
    {
        yield 'pending' => [CartStatus::PENDING];
        yield 'delivered' => [CartStatus::DELIVERED];
        yield 'canceled' => [CartStatus::CANCELED];
    }

    public function test_an_unknown_cart_is_a_404_problem(): void
    {
        $this->request('PUT', $this->url('api_cart_deliver_by_id', ['id' => Uuid::uuid4()->toString()]));

        $this->assertProblem(404, 'Cart');
    }

    public function test_a_malformed_id_is_a_404_problem(): void
    {
        $this->request('PUT', '/api/v1/carts/nope/deliver');

        $this->assertProblem(404);
    }
}
