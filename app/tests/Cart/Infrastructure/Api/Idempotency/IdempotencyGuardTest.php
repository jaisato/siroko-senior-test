<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Idempotency;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyGuard;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyRecord;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyStore;
use Siroko\Cart\Infrastructure\Api\Security\ApiCustomer;
use Siroko\Cart\Infrastructure\Api\Security\CurrentCustomer;
use Siroko\Cart\Domain\ValueObject\CustomerId;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The guard alone, over an in-memory store: what is executed, what is
 * replayed, what is refused.
 */
final class IdempotencyGuardTest extends TestCase
{
    private InMemoryIdempotencyStore $store;

    private MockClock $clock;

    private int $executions = 0;

    protected function setUp(): void
    {
        $this->store = new InMemoryIdempotencyStore();
        $this->clock = new MockClock('2026-09-06 10:00:00', 'UTC');
        $this->executions = 0;
    }

    public function test_without_a_key_every_request_is_executed_and_nothing_is_stored(): void
    {
        $guard = $this->guard();

        $guard->respond(self::request(), $this->producer(201));
        $guard->respond(self::request(), $this->producer(201));

        self::assertSame(2, $this->executions);
        self::assertSame([], $this->store->records);
    }

    public function test_the_same_key_and_request_replays_the_stored_response_without_executing_again(): void
    {
        $guard = $this->guard();

        $first = $guard->respond(self::request(key: 'k1'), $this->producer(201, ['id' => 'cart-1']));
        $second = $guard->respond(self::request(key: 'k1'), $this->producer(201, ['id' => 'cart-2']));

        self::assertSame(1, $this->executions, 'the second call was not executed');
        self::assertSame(201, $second->getStatusCode());
        self::assertSame($first->getContent(), $second->getContent(), 'the original answer, not a new one');
        self::assertSame('application/json', $second->headers->get('Content-Type'));
        self::assertNull($first->headers->get(IdempotencyRecord::REPLAYED_HEADER));
        self::assertSame('true', $second->headers->get(IdempotencyRecord::REPLAYED_HEADER));
    }

    public function test_the_same_key_with_a_different_request_is_a_422_problem(): void
    {
        $guard = $this->guard();
        $guard->respond(self::request(key: 'k1', body: '{"products": []}'), $this->producer(201));

        $response = $guard->respond(self::request(key: 'k1', body: '{"products": [1]}'), $this->producer(201));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('different request', (string) $response->getContent());
        self::assertSame(1, $this->executions);

        $otherPath = $guard->respond(self::request(key: 'k1', path: '/api/v1/other'), $this->producer(201));
        self::assertSame(422, $otherPath->getStatusCode(), 'the path is part of the request too');
    }

    /** A 404 or a 409 is as final as a 201; a 500 is not. */
    public function test_client_errors_are_remembered_but_server_errors_are_not(): void
    {
        $guard = $this->guard();

        $guard->respond(self::request(key: 'conflict'), $this->producer(409));
        $replayed = $guard->respond(self::request(key: 'conflict'), $this->producer(201));
        self::assertSame(409, $replayed->getStatusCode());
        self::assertSame('true', $replayed->headers->get(IdempotencyRecord::REPLAYED_HEADER));

        $guard->respond(self::request(key: 'outage'), $this->producer(500));
        $retried = $guard->respond(self::request(key: 'outage'), $this->producer(201));
        self::assertSame(201, $retried->getStatusCode(), 'the retry ran');
        self::assertNull($retried->headers->get(IdempotencyRecord::REPLAYED_HEADER));
        self::assertSame(3, $this->executions, 'the first of each pair and the retry of the outage');
    }

    public function test_an_expired_record_is_executed_again_and_replaced(): void
    {
        $guard = $this->guard(ttlSeconds: 60);
        $guard->respond(self::request(key: 'k1'), $this->producer(201, ['id' => 'first']));

        $this->clock->modify('+61 seconds');
        $renewed = $guard->respond(self::request(key: 'k1', body: '{"changed": true}'), $this->producer(201, ['id' => 'second']));

        self::assertSame(2, $this->executions);
        self::assertNull($renewed->headers->get(IdempotencyRecord::REPLAYED_HEADER));
        self::assertStringContainsString('second', (string) $renewed->getContent());
        self::assertCount(1, $this->store->records, 'the record was renewed, not duplicated');

        $replayed = $guard->respond(self::request(key: 'k1', body: '{"changed": true}'), $this->producer(201));
        self::assertStringContainsString('second', (string) $replayed->getContent());
        self::assertSame(2, $this->executions);
    }

    /** Right up to the deadline the record holds; at the deadline it is gone. */
    public function test_the_record_holds_until_its_ttl(): void
    {
        $guard = $this->guard(ttlSeconds: 60);
        $guard->respond(self::request(key: 'k1'), $this->producer(201));

        $this->clock->modify('+59 seconds');
        $guard->respond(self::request(key: 'k1'), $this->producer(201));
        self::assertSame(1, $this->executions);

        $this->clock->modify('+1 second');
        $guard->respond(self::request(key: 'k1'), $this->producer(201));
        self::assertSame(2, $this->executions);
    }

    /**
     * Storing the answer is a second write, after the cart's own transaction
     * has committed; a process killed in that gap leaves a claim nobody will
     * complete. On one shared lifetime that claim answered "in flight" for the
     * whole retention window - an hour here - in which the client could neither
     * recover its cart nor retry. A claim only holds a short lease; the long
     * life is what answering buys.
     */
    public function test_a_claim_that_never_answered_expires_on_its_lease_not_on_the_retention_window(): void
    {
        $guard = $this->guard(ttlSeconds: 3600);

        // The request runs, but the process dies before its answer is stored.
        $this->store->loseNextCompletion = true;
        $guard->respond(self::request(key: 'lost'), $this->producer(201));

        // And one that got all the way through.
        $guard->respond(self::request(key: 'answered'), $this->producer(201, ['id' => 'cart-1']));

        $this->clock->modify(\sprintf('+%d seconds', IdempotencyGuard::IN_PROGRESS_LEASE_SECONDS + 1));

        $guard->respond(self::request(key: 'lost'), $this->producer(201));
        self::assertSame(3, $this->executions, 'the abandoned claim let go, so the retry ran for real');

        $replayed = $guard->respond(self::request(key: 'answered'), $this->producer(201, ['id' => 'cart-2']));
        self::assertSame('true', $replayed->headers->get(IdempotencyRecord::REPLAYED_HEADER));
        self::assertStringContainsString('cart-1', (string) $replayed->getContent(), 'an answer keeps the full hour');
        self::assertSame(3, $this->executions);
    }

    /** Two customers may use the same key without ever seeing each other's response. */
    public function test_keys_are_scoped_to_the_customer(): void
    {
        $alice = $this->guard(customer: 'alice');
        $bob = $this->guard(customer: 'bob');

        $alice->respond(self::request(key: 'k1'), $this->producer(201, ['owner' => 'alice']));
        $bobs = $bob->respond(self::request(key: 'k1'), $this->producer(201, ['owner' => 'bob']));

        self::assertSame(2, $this->executions);
        self::assertStringContainsString('bob', (string) $bobs->getContent());
        self::assertNull($bobs->headers->get(IdempotencyRecord::REPLAYED_HEADER));
        self::assertCount(2, $this->store->records);
        self::assertSame('alice', array_values($this->store->records)[0]->scope());
    }

    /**
     * The key is claimed before the work runs, which is the only thing that
     * stops two simultaneous originals: recording afterwards would let both
     * create a cart and reserve stock, and only then notice the collision.
     */
    public function test_the_key_is_claimed_before_the_request_runs(): void
    {
        $guard = $this->guard();
        $claimedWhileRunning = null;

        $guard->respond(self::request(key: 'k1'), function () use (&$claimedWhileRunning): Response {
            $claimedWhileRunning = $this->store->find(IdempotencyGuard::recordId('', 'k1'));

            return new JsonResponse(['ok' => true], 201);
        });

        self::assertNotNull($claimedWhileRunning, 'the record was already there while the work ran');
        self::assertTrue($claimedWhileRunning->isPending(), 'and it had no answer yet');
        self::assertFalse($this->store->find(IdempotencyGuard::recordId('', 'k1'))?->isPending());
    }

    /** A second request arriving while the first is still working is told to wait. */
    public function test_a_request_still_in_flight_answers_409(): void
    {
        $guard = $this->guard();
        $inner = null;

        $guard->respond(self::request(key: 'k1'), function () use ($guard, &$inner): Response {
            $inner = $guard->respond(self::request(key: 'k1'), $this->producer(201));

            return new JsonResponse(['ok' => true], 201);
        });

        self::assertSame(409, $inner?->getStatusCode());
        self::assertSame('application/problem+json', $inner->headers->get('Content-Type'));
        self::assertStringContainsString('still being processed', (string) $inner->getContent());
        self::assertSame(0, $this->executions, 'the producer of the second request never ran');
    }

    /** Losing the claim by a hair is the same answer as finding it taken. */
    public function test_losing_the_claim_race_falls_back_to_the_winners_record(): void
    {
        $guard = $this->guard();
        $winner = IdempotencyRecord::claim(IdempotencyGuard::recordId('', 'k1'), '', 'k1', IdempotencyGuard::fingerprint(self::request(key: 'k1')), $this->clock->now(), new \DateInterval('PT3600S'));
        $this->store->claimedByAnother = $winner->completedWith(new JsonResponse(['id' => 'theirs'], 201), $this->clock->now(), new \DateInterval('PT3600S'));

        $response = $guard->respond(self::request(key: 'k1'), $this->producer(201, ['id' => 'mine']));

        self::assertSame(0, $this->executions);
        self::assertSame('true', $response->headers->get(IdempotencyRecord::REPLAYED_HEADER));
        self::assertStringContainsString('theirs', (string) $response->getContent());
    }

    /** An exception is not an answer: the claim goes back so a retry can run. */
    public function test_a_thrown_exception_releases_the_claim_and_reaches_the_caller(): void
    {
        $guard = $this->guard();

        try {
            $guard->respond(self::request(key: 'k1'), static fn(): Response => throw new \RuntimeException('the database went away'));
            self::fail('Expected the exception to reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('the database went away', $e->getMessage());
        }

        self::assertSame([], $this->store->records, 'nothing is holding the key');

        $retried = $guard->respond(self::request(key: 'k1'), $this->producer(201));
        self::assertSame(201, $retried->getStatusCode());
        self::assertSame(1, $this->executions);
    }

    public function test_a_malformed_key_is_a_400_problem_and_nothing_runs(): void
    {
        $guard = $this->guard();

        foreach (['   ', str_repeat('k', 256), "k\n1", 'with space'] as $key) {
            $response = $guard->respond(self::request(key: $key), $this->producer(201));

            self::assertSame(400, $response->getStatusCode(), $key);
            self::assertStringContainsString('Idempotency-Key', (string) $response->getContent());
        }

        self::assertSame(0, $this->executions);
    }

    public function test_a_ttl_below_one_second_is_a_configuration_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->guard(ttlSeconds: 0);
    }

    public function test_the_record_id_hides_the_key_and_the_fingerprint_covers_method_path_and_body(): void
    {
        self::assertSame(64, \strlen(IdempotencyGuard::recordId('', 'k1')));
        self::assertNotSame(IdempotencyGuard::recordId('', 'k1'), IdempotencyGuard::recordId('alice', 'k1'));
        self::assertStringNotContainsString('k1', IdempotencyGuard::recordId('', 'k1'));

        $base = IdempotencyGuard::fingerprint(self::request(body: '{"a":1}'));
        self::assertSame($base, IdempotencyGuard::fingerprint(self::request(body: '{"a":1}', server: ['HTTP_X_TRACE' => 'proxy-added'])), 'headers do not count');
        self::assertNotSame($base, IdempotencyGuard::fingerprint(self::request(body: '{"a":2}')));
        self::assertNotSame($base, IdempotencyGuard::fingerprint(self::request(method: 'PUT', body: '{"a":1}')));
    }

    private function guard(int $ttlSeconds = 3600, ?string $customer = null): IdempotencyGuard
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null === $customer ? null : new ApiCustomer(CustomerId::fromString($customer)));

        return new IdempotencyGuard($this->store, new CurrentCustomer($security), $this->clock, new ApiExceptionMapper(new NullLogger()), $ttlSeconds);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return callable(): Response
     */
    private function producer(int $status, array $body = ['ok' => true]): callable
    {
        return function () use ($status, $body): Response {
            ++$this->executions;

            return new JsonResponse($body, $status);
        };
    }

    /**
     * @param array<string, string> $server
     */
    private static function request(?string $key = null, string $body = '{"products": []}', string $path = '/api/v1/carts', string $method = 'POST', array $server = []): Request
    {
        if (null !== $key) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
        }

        return Request::create($path, $method, server: $server + ['CONTENT_TYPE' => 'application/json'], content: $body);
    }
}

final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, IdempotencyRecord> */
    public array $records = [];

    /**
     * A record to hand back on the next find(), as if another request had
     * claimed or answered the key between this one's find() and its claim().
     */
    public ?IdempotencyRecord $claimedByAnother = null;

    /**
     * Drops the next complete(), the way a process killed between the business
     * write and its stored answer does: the claim stays, unanswered, forever.
     */
    public bool $loseNextCompletion = false;

    public function find(string $id): ?IdempotencyRecord
    {
        return $this->records[$id] ?? null;
    }

    public function claim(IdempotencyRecord $pending): bool
    {
        if (null !== $this->claimedByAnother) {
            $this->records[$pending->id()] = $this->claimedByAnother;
            $this->claimedByAnother = null;

            return false;
        }

        $existing = $this->records[$pending->id()] ?? null;
        if (null !== $existing && !$existing->isExpiredAt($pending->createdAt())) {
            return false;
        }

        $this->records[$pending->id()] = $pending;

        return true;
    }

    public function complete(IdempotencyRecord $record): void
    {
        if ($this->loseNextCompletion) {
            $this->loseNextCompletion = false;

            return;
        }

        $this->records[$record->id()] = $record;
    }

    public function release(string $id): void
    {
        if (($this->records[$id] ?? null)?->isPending() === true) {
            unset($this->records[$id]);
        }
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $before = \count($this->records);
        $this->records = array_filter($this->records, static fn(IdempotencyRecord $record): bool => !$record->isExpiredAt($now));

        return max(0, $before - \count($this->records));
    }
}
