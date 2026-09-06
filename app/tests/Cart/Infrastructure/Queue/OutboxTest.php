<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Queue;

use Doctrine\DBAL\Connection;
use Siroko\Cart\Domain\Event\DomainEventPublisher;
use Siroko\Cart\Domain\Event\Subscriber\QueueingSubscriber;
use Siroko\Cart\Infrastructure\Queue\MessageDispatcher;
use Siroko\Tests\Cart\Domain\Event\FakeEvent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * The guarantee the handlers rely on: the doctrine transport writes the queue
 * row through the application's own connection, so an event published inside
 * a transaction is committed - or rolled back - with the change that raised
 * it. That is why QueueingSubscriber dispatches as the event is published
 * rather than after the handler has returned and the transaction is closed.
 *
 * The test environment routes messages to an in-memory transport, which has
 * no such semantics, so the doctrine transport is assembled here by hand on
 * the test connection with the same serializer production uses. Runs on
 * SQLite and MySQL alike.
 */
final class OutboxTest extends KernelTestCase
{
    private const QUEUE = 'outbox_test';

    private Connection $db;

    private DomainEventPublisher $publisher;

    private int $subscription;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->db = static::getContainer()->get(Connection::class);

        $transport = new DoctrineTransport(
            // auto_setup only matters where the migrations have not run
            // (SQLite): it creates messenger_messages on first use, inside
            // the transaction the test is wrapped in.
            new QueueConnection(['table_name' => 'messenger_messages', 'queue_name' => self::QUEUE, 'auto_setup' => true], $this->db),
            new PhpSerializer(),
        );
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator(
                [FakeEvent::class => ['outbox']],
                new ServiceLocator(['outbox' => static fn(): DoctrineTransport => $transport]),
            )),
        ]);

        $this->publisher = DomainEventPublisher::instance();
        $this->subscription = $this->publisher->subscribe(new QueueingSubscriber(new MessageDispatcher($bus)));
    }

    protected function tearDown(): void
    {
        $this->publisher->unsubscribe($this->subscription);

        parent::tearDown();
    }

    public function test_an_event_published_in_a_committed_transaction_is_queued(): void
    {
        $this->db->transactional(function (): void {
            $this->publisher->publish(new FakeEvent());
            $this->publisher->publish(new FakeEvent());
        });

        self::assertSame(2, $this->queued());
    }

    public function test_an_event_published_in_a_rolled_back_transaction_is_never_queued(): void
    {
        try {
            $this->db->transactional(function (): void {
                $this->publisher->publish(new FakeEvent());

                self::assertSame(1, $this->queued(), 'visible inside the transaction');

                throw new \RuntimeException('the write failed after the event was published');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('the write failed after the event was published', $e->getMessage());
        }

        self::assertSame(0, $this->queued());
    }

    private function queued(): int
    {
        try {
            return (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
                ['queue' => self::QUEUE],
            );
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            // Rolled back together with the table auto_setup created (SQLite).
            return 0;
        }
    }
}
