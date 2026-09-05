<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Idempotency;

use PHPUnit\Framework\TestCase;
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

        return new IdempotencyGuard($this->store, new CurrentCustomer($security), $this->clock, new ApiExceptionMapper(), $ttlSeconds);
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

    public function find(string $id): ?IdempotencyRecord
    {
        return $this->records[$id] ?? null;
    }

    public function save(IdempotencyRecord $record): void
    {
        $this->records[$record->id()] = $record;
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $before = \count($this->records);
        $this->records = array_filter($this->records, static fn(IdempotencyRecord $record): bool => !$record->isExpiredAt($now));

        return max(0, $before - \count($this->records));
    }
}
