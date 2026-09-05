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

        self::assertSame([0, 1], array_keys($body['items']), 'the lines are a list');

        foreach ($body['items'] as $item) {
            self::assertArrayHasKey('id', $item);
            self::assertArrayHasKey('name', $item);
            self::assertArrayHasKey('code', $item);
            self::assertArrayHasKey('productId', $item);
            self::assertSame("19,99\u{a0}€", $item['price']);
            self::assertSame(1, $item['quantity']);
        }
    }

    public function test_the_cart_adds_up_its_lines(): void
    {
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [
            [$this->persistProduct('Gafas', amount: '129.95'), 2],
            [$this->persistProduct('Funda', amount: '9.99'), 1],
        ]);

        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart->id()->toString()]));

        $body = $this->json();
        self::assertSame(3, $body['itemCount']);
        self::assertSame('EUR', $body['currency']);
        self::assertSame(['amount' => '269.89', 'currency' => 'EUR'], $body['subtotal']);
        self::assertSame(['amount' => '269.89', 'currency' => 'EUR'], $body['total']);

        $lines = array_column($body['items'], 'lineTotal', 'name');
        self::assertSame(['amount' => '259.90', 'currency' => 'EUR'], $lines['Gafas']);
        self::assertSame(['amount' => '9.99', 'currency' => 'EUR'], $lines['Funda']);
        self::assertSame(['amount' => '129.95', 'currency' => 'EUR'], array_column($body['items'], 'unitPrice', 'name')['Gafas']);
    }

    public function test_a_line_reports_how_many_units_it_holds(): void
    {
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$this->persistProduct(), 3]]);

        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart->id()->toString()]));

        self::assertSame(3, array_values($this->json()['items'])[0]['quantity']);
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
        $body = $this->json();
        self::assertSame([], $body['items']);
        self::assertSame(0, $body['itemCount']);
        self::assertNull($body['currency']);
        self::assertNull($body['subtotal']);
        self::assertNull($body['total']);
        self::assertStringContainsString('"items":[]', (string) $this->client->getResponse()->getContent(), 'an empty list, not an empty object');
    }
}
