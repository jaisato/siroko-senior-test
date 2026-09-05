<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Queue;

use Siroko\Cart\Application\Command\Order\SendOrderConfirmationCommand;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Event\CartCheckedOut;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\OrderId;
use Siroko\Cart\Infrastructure\Event\EventCommandFactory;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * The event pipeline, end to end, through the real wiring: a checkout raises
 * `CartCheckedOut` inside the command, the command bus middleware hands it to
 * Messenger, Messenger routes it to the `async` transport, and the worker's
 * handler (DomainEventConsumer) turns it into SendOrderConfirmationCommand on
 * the CLI bus, which confirms the order.
 *
 * The transport is in memory in the test environment, so the "worker" step is
 * the envelope being fed back to the bus as received - exactly what the real
 * worker does with a row of messenger_messages.
 *
 * Until now every piece of this pipeline existed and nothing ever travelled
 * through it.
 */
final class CartCheckedOutPipelineTest extends ApiTestCase
{
    public function test_a_checkout_reaches_the_confirmation_handler_through_the_queue(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct());

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));
        self::assertResponseStatusCodeSame(200);
        $orderId = OrderId::fromString($this->json()['order']['id']);

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent, 'exactly one event was queued');
        self::assertInstanceOf(CartCheckedOut::class, $sent[0]->getMessage());

        $before = $this->em()->find(Order::class, $orderId);
        self::assertInstanceOf(Order::class, $before);
        self::assertFalse($before->isConfirmed(), 'the request itself does not confirm; the worker does');
        $this->em()->clear();

        $this->consume($sent[0]);

        $after = $this->em()->find(Order::class, $orderId);
        self::assertInstanceOf(Order::class, $after);
        self::assertTrue($after->isConfirmed(), 'the handler ran and confirmed the order');

        $this->request('GET', $this->url('api_get_order_by_id', ['id' => $orderId->toString()]));
        self::assertResponseStatusCodeSame(200);
        self::assertNotNull($this->json()['confirmedAt']);
    }

    /** Redelivery is normal for a queue; the second delivery must be harmless. */
    public function test_consuming_the_same_event_twice_confirms_once(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct());

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));
        $orderId = OrderId::fromString($this->json()['order']['id']);
        $envelope = $this->asyncTransport()->getSent()[0];

        $this->consume($envelope);
        $this->em()->clear();
        $first = $this->em()->find(Order::class, $orderId)?->confirmedAt();

        $this->consume($envelope);
        $this->em()->clear();
        $second = $this->em()->find(Order::class, $orderId)?->confirmedAt();

        self::assertNotNull($first);
        self::assertEquals($first, $second);
    }

    /** The mapping the consumer relies on is configuration; this pins it. */
    public function test_the_event_is_mapped_to_the_confirmation_command(): void
    {
        $factory = static::getContainer()->get(EventCommandFactory::class);
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct());
        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]));
        $event = $this->asyncTransport()->getSent()[0]->getMessage();
        self::assertInstanceOf(CartCheckedOut::class, $event);

        self::assertSame(SendOrderConfirmationCommand::class, $factory->get($event));
    }
}
