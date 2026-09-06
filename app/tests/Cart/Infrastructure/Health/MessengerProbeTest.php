<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Health;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Infrastructure\Health\MessengerProbe;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The probe against the transport production uses, over SQLite: what it
 * reports before and after the queue table exists.
 */
final class MessengerProbeTest extends TestCase
{
    public function test_the_doctrine_transport_fails_without_its_table_and_passes_once_migrated(): void
    {
        $transport = new DoctrineTransport(
            new Connection(['table_name' => 'messenger_messages', 'queue_name' => 'async', 'auto_setup' => false], DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])),
            new PhpSerializer(),
        );
        $probe = new MessengerProbe($transport);

        try {
            $probe->check();
            self::fail('messenger_messages does not exist yet');
        } catch (TransportException $e) {
            // The transport wraps the driver's complaint; the cause is the
            // table the migration creates, without which the worker cannot run.
            self::assertInstanceOf(TableNotFoundException::class, $e->getPrevious());
        }

        $transport->setup();

        $probe->check();
        self::assertSame('messenger', $probe->name());
    }

    /** The transport of the test environment: nothing outside the process, nothing to fail. */
    public function test_an_in_process_transport_has_nothing_to_probe(): void
    {
        $probe = new MessengerProbe(new InMemoryTransport());

        $probe->check();

        self::assertSame('messenger', $probe->name());
    }

    public function test_a_transport_that_cannot_be_counted_is_the_probes_failure(): void
    {
        $probe = new MessengerProbe(self::brokenCountableTransport(new \RuntimeException('broker unreachable')));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('broker unreachable');

        $probe->check();
    }

    private static function brokenCountableTransport(\Throwable $failure): TransportInterface
    {
        return new class ($failure) implements TransportInterface, MessageCountAwareInterface {
            public function __construct(private readonly \Throwable $failure) {}

            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void {}

            public function reject(Envelope $envelope): void {}

            public function send(Envelope $envelope): Envelope
            {
                return $envelope;
            }

            public function getMessageCount(): int
            {
                throw $this->failure;
            }
        };
    }
}
