<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Health;

use Siroko\Cart\Infrastructure\Health\MessengerProbe;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;
use Siroko\Tests\Cart\Infrastructure\Health\FailingProbe;

/**
 * GET /health through the real wiring: the database of the test profile and
 * the in-memory transport.
 */
final class HealthControllerTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        self::tokens('');
    }

    public function test_a_healthy_service_answers_200_with_every_check(): void
    {
        $this->request('GET', '/health');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ok', 'checks' => ['database' => 'ok', 'messenger' => 'ok']], $this->json());

        $headers = $this->client->getResponse()->headers;
        self::assertStringStartsWith('application/json', (string) $headers->get('Content-Type'));
        self::assertTrue($headers->hasCacheControlDirective('no-store'), 'a probe wants the answer of this moment');
    }

    public function test_a_failing_dependency_answers_503_and_names_the_check_but_not_the_reason(): void
    {
        static::getContainer()->set(MessengerProbe::class, new FailingProbe('messenger'));

        $this->request('GET', '/health');

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['status' => 'fail', 'checks' => ['database' => 'ok', 'messenger' => 'fail']], $this->json());
        self::assertStringNotContainsString('hunter2', (string) $this->client->getResponse()->getContent());
    }

    /** Load balancers and orchestrators carry no token; the endpoint is outside what the authenticator guards. */
    public function test_it_stays_public_when_api_tokens_are_on(): void
    {
        self::tokens('alice-token:alice');

        $this->request('GET', '/health');
        self::assertResponseStatusCodeSame(200);

        $this->request('GET', $this->url('api_list_carts'));
        $this->assertProblem(401);
    }

    public function test_only_get_is_served(): void
    {
        $this->request('POST', '/health');

        self::assertResponseStatusCodeSame(405);
    }

    private static function tokens(string $spec): void
    {
        $_ENV['API_TOKENS'] = $spec;
        $_SERVER['API_TOKENS'] = $spec;
        putenv('API_TOKENS=' . $spec);
    }
}
