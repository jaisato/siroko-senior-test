<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Idempotency;

use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyRecord;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * Idempotency-Key on the endpoints that honour it, end to end: a retry gets
 * the original response and moves no stock.
 */
final class IdempotencyKeyTest extends ApiTestCase
{
    public function test_retrying_a_cart_creation_replays_the_cart_and_reserves_stock_once(): void
    {
        $product = $this->persistProduct(stock: 5);
        $body = ['products' => [['productId' => $product->id()->toString(), 'quantity' => 2]]];

        $this->request('POST', $this->url('api_create_cart'), $body, self::key('create-1'));
        self::assertResponseStatusCodeSame(201);
        $first = $this->json();
        self::assertNull($this->client->getResponse()->headers->get(IdempotencyRecord::REPLAYED_HEADER));

        $this->request('POST', $this->url('api_create_cart'), $body, self::key('create-1'));
        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $this->json(), 'the very same cart');
        self::assertSame('true', $this->client->getResponse()->headers->get(IdempotencyRecord::REPLAYED_HEADER));

        self::assertSame(3, $this->stockOf($product), 'two units reserved once, not twice');
        self::assertCount(1, $this->em()->getRepository(Cart::class)->findAll(), 'one cart');
    }

    public function test_the_same_key_with_another_payload_is_a_422_problem(): void
    {
        $product = $this->persistProduct(stock: 5);

        $this->request('POST', $this->url('api_create_cart'), ['products' => [['productId' => $product->id()->toString(), 'quantity' => 1]]], self::key('create-2'));
        self::assertResponseStatusCodeSame(201);

        $this->request('POST', $this->url('api_create_cart'), ['products' => [['productId' => $product->id()->toString(), 'quantity' => 3]]], self::key('create-2'));

        $this->assertProblem(422, 'different request');
        self::assertSame(4, $this->stockOf($product), 'the second request did not run');
    }

    public function test_retrying_an_add_adds_the_units_once(): void
    {
        $product = $this->persistProduct(stock: 5);
        $cart = $this->persistCart();
        $url = $this->url('api_add_cart_product_by_id', ['cartId' => $cart->id()->toString(), 'productId' => $product->id()->toString()]);

        $this->request('PUT', $url, ['quantity' => 2], self::key('add-1'));
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();

        $this->request('PUT', $url, ['quantity' => 2], self::key('add-1'));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $this->json());
        self::assertSame('true', $this->client->getResponse()->headers->get(IdempotencyRecord::REPLAYED_HEADER));
        self::assertSame(3, $this->stockOf($product));
        self::assertSame(2, $this->json()['items'][0]['quantity']);
    }

    /** Without the key a retried checkout is a 409; with it, the same order comes back. */
    public function test_retrying_a_checkout_replays_the_same_order(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct());
        $url = $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]);

        $this->request('PUT', $url, server: self::key('checkout-1'));
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();

        $this->request('PUT', $url, server: self::key('checkout-1'));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($first['order']['id'], $this->json()['order']['id']);
        self::assertSame('true', $this->client->getResponse()->headers->get(IdempotencyRecord::REPLAYED_HEADER));

        $this->request('PUT', $url);
        $this->assertProblem(409, 'not pending');
    }

    /** A stored error is replayed too: the answer to that request was final. */
    public function test_a_stored_client_error_is_replayed(): void
    {
        $cart = $this->persistCart(CartStatus::PAID, $this->persistProduct());
        $url = $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]);

        $this->request('PUT', $url, server: self::key('checkout-paid'));
        $this->assertProblem(409, 'not pending');

        $this->request('PUT', $url, server: self::key('checkout-paid'));
        $this->assertProblem(409, 'not pending');
        self::assertSame('true', $this->client->getResponse()->headers->get(IdempotencyRecord::REPLAYED_HEADER));
    }

    public function test_requests_without_the_header_are_not_deduplicated(): void
    {
        $product = $this->persistProduct(stock: 5);
        $body = ['products' => [['productId' => $product->id()->toString(), 'quantity' => 1]]];

        $this->request('POST', $this->url('api_create_cart'), $body);
        $this->request('POST', $this->url('api_create_cart'), $body);

        self::assertSame(3, $this->stockOf($product), 'two carts, two reservations');
    }

    public function test_a_malformed_key_is_a_400_problem(): void
    {
        $product = $this->persistProduct(stock: 5);

        $this->request('POST', $this->url('api_create_cart'), ['products' => [['productId' => $product->id()->toString(), 'quantity' => 1]]], self::key(str_repeat('k', 256)));

        $this->assertProblem(400, 'Idempotency-Key');
        self::assertSame(5, $this->stockOf($product));
    }

    /**
     * @return array<string, string>
     */
    private static function key(string $key): array
    {
        return ['HTTP_IDEMPOTENCY_KEY' => $key];
    }
}
