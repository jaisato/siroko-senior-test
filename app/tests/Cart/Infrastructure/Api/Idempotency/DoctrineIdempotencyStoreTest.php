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
 * The store against the database: a claim round-trips and is answered, only
 * one claim on an id wins, an expired one is taken over, the purge removes
 * exactly the expired rows, and a write still lands once the EntityManager
 * has given up on the request. Runs on SQLite and MySQL alike.
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

        $claim = IdempotencyRecord::claim($id, 'alice', 'k1', 'fp', $now, new \DateInterval('PT1H'));
        self::assertTrue($this->store->claim($claim));

        $pending = $this->store->find($id);
        self::assertInstanceOf(IdempotencyRecord::class, $pending);
        self::assertTrue($pending->isPending(), 'claimed, not answered');

        $this->store->complete($claim->completedWith($response, $now, new \DateInterval('PT1H')));

        $reloaded = $this->store->find($id);
        self::assertInstanceOf(IdempotencyRecord::class, $reloaded);
        self::assertSame($id, $reloaded->id());
        self::assertSame('alice', $reloaded->scope());
        self::assertSame('k1', $reloaded->requestKey());
        self::assertFalse($reloaded->isPending());
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

    /**
     * The claim is what serialises two originals: it is an insert on the
     * primary key, so the second one is simply told no.
     */
    public function test_only_one_claim_on_an_id_wins_while_it_is_live(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $id = IdempotencyGuard::recordId('', 'k1');
        $ttl = new \DateInterval('PT1H');

        self::assertTrue($this->store->claim(IdempotencyRecord::claim($id, '', 'k1', 'fp-1', $now, $ttl)));
        self::assertFalse($this->store->claim(IdempotencyRecord::claim($id, '', 'k1', 'fp-1', $now, $ttl)), 'the second request did not get it');

        // Nor once the first one has answered.
        $this->store->complete(IdempotencyRecord::claim($id, '', 'k1', 'fp-1', $now, $ttl)->completedWith(new JsonResponse(['id' => 'first'], 201), $now, $ttl));
        self::assertFalse($this->store->claim(IdempotencyRecord::claim($id, '', 'k1', 'fp-1', $now, $ttl)));
    }

    public function test_an_expired_record_is_taken_over_by_the_next_claim(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $id = IdempotencyGuard::recordId('', 'k1');
        $ttl = new \DateInterval('PT1H');

        $first = IdempotencyRecord::claim($id, '', 'k1', 'fp-1', $now, $ttl);
        self::assertTrue($this->store->claim($first));
        $this->store->complete($first->completedWith(new JsonResponse(['id' => 'first'], 201), $now, $ttl));

        $later = $now->modify('+2 hours');
        $second = IdempotencyRecord::claim($id, '', 'k1', 'fp-2', $later, $ttl);
        self::assertTrue($this->store->claim($second), 'the old record had expired');

        $taken = $this->store->find($id);
        self::assertInstanceOf(IdempotencyRecord::class, $taken);
        self::assertTrue($taken->isPending(), 'the old answer went with the old record');
        self::assertTrue($taken->matches('fp-2'));

        $this->store->complete($second->completedWith(new JsonResponse(['id' => 'second'], 200), $later, $ttl));
        $reloaded = $this->store->find($id);
        self::assertInstanceOf(IdempotencyRecord::class, $reloaded);
        self::assertSame(200, $reloaded->replay()->getStatusCode());
        self::assertStringContainsString('second', (string) $reloaded->replay()->getContent());
        self::assertFalse($reloaded->isExpiredAt($later->modify('+59 minutes')));
    }

    /** Releasing gives an unanswered key back; an answered one stays put. */
    public function test_release_drops_a_claim_that_never_answered_and_leaves_the_rest(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $ttl = new \DateInterval('PT1H');

        $abandoned = IdempotencyRecord::claim(IdempotencyGuard::recordId('', 'boom'), '', 'boom', 'fp', $now, $ttl);
        $this->store->claim($abandoned);
        $this->store->release($abandoned->id());
        self::assertNull($this->store->find($abandoned->id()));
        self::assertTrue($this->store->claim($abandoned), 'and the key can be claimed again');

        $answered = IdempotencyRecord::claim(IdempotencyGuard::recordId('', 'done'), '', 'done', 'fp', $now, $ttl);
        $this->store->claim($answered);
        $this->store->complete($answered->completedWith(new JsonResponse(['ok' => true], 201), $now, $ttl));
        $this->store->release($answered->id());
        self::assertNotNull($this->store->find($answered->id()), 'an answered record is what retries replay');
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
        $claim = IdempotencyRecord::claim($id, '', 'after-failure', 'fp', new \DateTimeImmutable(), new \DateInterval('PT1H'));
        $this->store->claim($claim);
        $this->store->complete($claim->completedWith(new JsonResponse(['status' => 409], 409), new \DateTimeImmutable(), new \DateInterval('PT1H')));

        $stored = $this->store->find($id);
        self::assertInstanceOf(IdempotencyRecord::class, $stored);
        self::assertSame(409, $stored->replay()->getStatusCode());
    }

    public function test_purge_removes_the_expired_records_only(): void
    {
        $now = new \DateTimeImmutable('2026-09-06 10:00:00', new \DateTimeZone('UTC'));
        $ttl = new \DateInterval('PT1H');
        $response = new JsonResponse(['ok' => true]);

        foreach (['old' => '-2 hours', 'on-the-dot' => '-1 hour', 'fresh' => 'now'] as $key => $when) {
            $at = 'now' === $when ? $now : $now->modify($when);
            $claim = IdempotencyRecord::claim(IdempotencyGuard::recordId('', $key), '', $key, 'fp', $at, $ttl);
            $this->store->claim($claim);
            $this->store->complete($claim->completedWith($response, $at, $ttl));
        }

        self::assertSame(2, $this->store->purgeExpired($now));

        self::assertNull($this->store->find(IdempotencyGuard::recordId('', 'old')));
        self::assertNull($this->store->find(IdempotencyGuard::recordId('', 'on-the-dot')));
        self::assertNotNull($this->store->find(IdempotencyGuard::recordId('', 'fresh')));
        self::assertSame(0, $this->store->purgeExpired($now), 'nothing left to purge');
    }
}
