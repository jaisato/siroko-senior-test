<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Event\CartCheckedOut;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\OrderId;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

final class CheckoutCartControllerTest extends ApiTestCase
{
    public function test_cart_checkout_by_id_pays_the_cart_and_places_an_order(): void
    {
        $product = $this->persistProduct('Gafas', amount: '129.95', stock: 5);
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 2]]);

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();

        self::assertSame($cart->id()->toString(), $body['cart']['id']);
        self::assertSame(CartStatus::PAID, $body['cart']['status']);
        self::assertCount(1, $body['cart']['items'], 'the lines are still there');
        self::assertSame(CartStatus::PAID, $this->reloadCart($cart)->status()->toInt());

        $order = $body['order'];
        self::assertTrue(Uuid::isValid($order['id']));
        self::assertSame($cart->id()->toString(), $order['cartId']);
        self::assertSame(2, $order['itemCount']);
        self::assertSame(['amount' => '259.90', 'currency' => 'EUR'], $order['total']);
        self::assertCount(1, $order['lines']);
        self::assertSame('Gafas', $order['lines'][0]['name']);
        self::assertSame(2, $order['lines'][0]['quantity']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $order['createdAt']);
        self::assertNull($order['confirmedAt'], 'the confirmation is the worker\'s job');

        $stored = $this->em()->find(Order::class, OrderId::fromString($order['id']));
        self::assertInstanceOf(Order::class, $stored, 'the order was persisted with the checkout');
        self::assertSame('259.90', $stored->total()->amount());

        self::assertSame(5, $this->stockOf($product), 'paying does not move stock: the units were reserved when the line was added');
    }

    /** The event leaves the request through the bus, bound for the `async` queue. */
    public function test_a_checkout_queues_a_cart_checked_out_event(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct());

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        $event = $sent[0]->getMessage();
        self::assertInstanceOf(CartCheckedOut::class, $event);
        self::assertSame($this->json()['order']['id'], $event->orderId());
        self::assertSame($cart->id()->toString(), $event->cartId());
    }

    /** Paying twice is the client's conflict, not a server failure. */
    public function test_a_second_checkout_is_a_409_problem(): void
    {
        $cart = $this->persistCart(CartStatus::PAID, $this->persistProduct());

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));

        $this->assertProblem(409, 'not pending');
        self::assertSame([], $this->asyncTransport()->getSent(), 'a refused checkout announces nothing');
    }

    /** Checking out an empty cart used to produce a "paid" cart for nothing. */
    public function test_an_empty_cart_is_a_409_problem(): void
    {
        $cart = $this->persistCart();

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));

        $this->assertProblem(409, 'empty');
        self::assertTrue($this->reloadCart($cart)->isPending());
        self::assertSame([], $this->asyncTransport()->getSent());
    }

    public function test_an_unknown_cart_is_a_404_problem(): void
    {
        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => Uuid::uuid4()->toString()]));

        $this->assertProblem(404, 'Cart');
    }
}
