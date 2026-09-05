<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Idempotency;

use Doctrine\ORM\EntityManagerInterface;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyGuard;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyRecord;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The store against the database: the record round-trips, an expired one is
 * replaced, the purge removes exactly the expired rows, and a write still
 * lands once the EntityManager has given up on the request. Runs on SQLite
 * and MySQL alike.
 */
final class DoctrineIdempotencyStoreTest extends KernelTestCase
{
    private IdempotencyStore $store;

    protected function setUp(): void
    {
        self::bootKernel();

        $store = static::getContainer()->get(IdempotencyStore::class);
        $this->store = $store;
    }

    public function test_a_record_round_trips_and_replays_its_response(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $response = new JsonResponse(['id' => 'cart-1', 'total' => ['amount' => '10.00', 'currency' => 'EUR']], 201);
        $id = IdempotencyGuard::recordId('alice', 'k1');

        $this->store->save(IdempotencyRecord::capture($id, 'alice', 'k1', 'fp', $response, $now, new \DateInterval('PT1H')));

        $reloaded = $this->store->find($id);
        self::assertInstanceOf(IdempotencyRecord::class, $reloaded);
        self::assertSame($id, $reloaded->id());
        self::assertSame('alice', $reloaded->scope());
        self::assertSame('k1', $reloaded->requestKey());
        self::assertTrue($reloaded->matches('fp'));
        self::assertFalse($reloaded->matches('other'));
        self::assertEquals($now, $reloaded->createdAt());
        self::assertFalse($reloaded->isExpiredAt($now->modify('+59 minutes')));
        self::assertTrue($reloaded->isExpiredAt($now->modify('+1 hour')));

        $replay = $reloaded->replay();
        self::assertSame(201, $replay->getStatusCode());
        self::assertSame($response->getContent(), $replay->getContent());
        self::assertSame('application/json', $replay->headers->get('Content-Type'));
        self::assertSame('true', $replay->headers->get(IdempotencyRecord::REPLAYED_HEADER));

        self::assertNull($this->store->find(IdempotencyGuard::recordId('alice', 'unknown')));
    }

    public function test_saving_under_an_existing_id_replaces_the_record(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $id = IdempotencyGuard::recordId('', 'k1');
        $ttl = new \DateInterval('PT1H');

        $this->store->save(IdempotencyRecord::capture($id, '', 'k1', 'fp-1', new JsonResponse(['id' => 'first'], 201), $now, $ttl));
        $this->store->save(IdempotencyRecord::capture($id, '', 'k1', 'fp-2', new JsonResponse(['id' => 'second'], 200), $now->modify('+2 hours'), $ttl));

        $reloaded = $this->store->find($id);
        self::assertInstanceOf(IdempotencyRecord::class, $reloaded);
        self::assertTrue($reloaded->matches('fp-2'));
        self::assertSame(200, $reloaded->replay()->getStatusCode());
        self::assertStringContainsString('second', (string) $reloaded->replay()->getContent());
        self::assertFalse($reloaded->isExpiredAt($now->modify('+2 hours 59 minutes')));
    }

    /**
     * The domain refuses a request inside the transaction, the EntityManager
     * closes: the 409 the client is about to get must still be remembered.
     */
    public function test_a_record_is_written_after_the_entity_manager_was_closed_by_a_failed_transaction(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        try {
            $em->wrapInTransaction(static function (): void {
                throw new \DomainException('the checkout was refused');
            });
        } catch (\DomainException) {
        }
        self::assertFalse($em->isOpen(), 'the failed transaction closed the manager');

        $id = IdempotencyGuard::recordId('', 'after-failure');
        $this->store->save(IdempotencyRecord::capture($id, '', 'after-failure', 'fp', new JsonResponse(['status' => 409], 409), new \DateTimeImmutable(), new \DateInterval('PT1H')));

        $stored = $this->store->find($id);
        self::assertInstanceOf(IdempotencyRecord::class, $stored);
        self::assertSame(409, $stored->replay()->getStatusCode());
    }

    public function test_purge_removes_the_expired_records_only(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $ttl = new \DateInterval('PT1H');
        $response = new JsonResponse(['ok' => true]);

        $this->store->save(IdempotencyRecord::capture(IdempotencyGuard::recordId('', 'old'), '', 'old', 'fp', $response, $now->modify('-2 hours'), $ttl));
        $this->store->save(IdempotencyRecord::capture(IdempotencyGuard::recordId('', 'on-the-dot'), '', 'on-the-dot', 'fp', $response, $now->modify('-1 hour'), $ttl));
        $this->store->save(IdempotencyRecord::capture(IdempotencyGuard::recordId('', 'fresh'), '', 'fresh', 'fp', $response, $now, $ttl));

        self::assertSame(2, $this->store->purgeExpired($now));

        self::assertNull($this->store->find(IdempotencyGuard::recordId('', 'old')));
        self::assertNull($this->store->find(IdempotencyGuard::recordId('', 'on-the-dot')));
        self::assertNotNull($this->store->find(IdempotencyGuard::recordId('', 'fresh')));
        self::assertSame(0, $this->store->purgeExpired($now), 'nothing left to purge');
    }
}
