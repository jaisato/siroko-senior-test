<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

final class GetCartControllerTest extends ApiTestCase
{
    public function test_get_cart_by_id(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct('First'), $this->persistProduct('Second'));

        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();

        self::assertSame($cart->id()->toString(), $body['id']);
        self::assertSame(CartStatus::PENDING, $body['status']);
        self::assertIsArray($body['items']);
        self::assertCount(2, $body['items']);

        foreach ($body['items'] as $itemId => $item) {
            self::assertSame($itemId, $item['id'], 'items are keyed by their id');
            self::assertArrayHasKey('name', $item);
            self::assertArrayHasKey('code', $item);
            self::assertSame("19,99\u{a0}€", $item['price']);
        }
    }

    /**
     * `ofId()` returned null and a `@var Cart` annotation hid it, so this was a
     * TypeError and a 500.
     */
    public function test_an_unknown_cart_is_a_404_problem(): void
    {
        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => Uuid::uuid4()->toString()]));

        $this->assertProblem(404, 'not found');
    }

    /**
     * The route requires a UUID, so a malformed id never reaches the
     * controller: the router answers 404, in the same RFC 7807 shape.
     */
    public function test_a_malformed_id_is_a_404_problem(): void
    {
        $this->request('GET', '/api/v1/carts/not-a-uuid');

        $this->assertProblem(404);
    }

    /**
     * The routes were bound to `host: localhost`, so the same request through
     * a proxy or from another container answered 404.
     */
    public function test_the_api_answers_whatever_the_host_header_is(): void
    {
        $cart = $this->persistCart();

        $this->request(
            'GET',
            $this->url('api_get_cart_by_id', ['id' => $cart->id()->toString()]),
            server: ['HTTP_HOST' => 'api.example.test'],
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame($cart->id()->toString(), $this->json()['id']);
    }

    public function test_an_empty_cart_lists_no_items(): void
    {
        $cart = $this->persistCart();

        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['items']);
    }
}
