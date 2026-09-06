<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\OpenApi\OpenApiFactoryDecorator;
use Siroko\Cart\Infrastructure\Api\OpenApi\Problem;
use Siroko\Cart\Infrastructure\Api\Security\ApiTokenAuthenticator;
use Symfony\Component\Routing\RouterInterface;

/**
 * The OpenAPI document is a contract, and this pins the parts of it that
 * nothing else would notice drifting: every route is documented, every
 * operation says which errors it can answer, and every one of those errors
 * is the same RFC 7807 problem the mapper sends.
 *
 * API Platform fills in generic stubs for an operation that declares nothing
 * ("Invalid input", "Unprocessable entity", its own Error schema). Those read
 * as documentation while describing a format this API never sends, so an
 * operation that leaves them in fails here.
 */
final class OpenApiDocumentationTest extends ApiTestCase
{
    private const API_PREFIX = '/api/v1/';

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** The router and the document know the same set of API operations, nothing more on either side. */
    public function test_every_route_of_the_api_is_documented_and_nothing_else_is(): void
    {
        $documented = [];

        foreach ($this->operations() as $id => $operation) {
            $documented[] = $id;
        }

        $routed = [];
        $router = static::getContainer()->get(RouterInterface::class);

        foreach ($router->getRouteCollection() as $route) {
            // API Platform appends the optional format to every route; the document leaves it out.
            $path = preg_replace('/(\.\{_format\}|\{\._format\})$/', '', $route->getPath());

            if (null === $path || !str_starts_with($path, self::API_PREFIX)) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                $routed[] = strtoupper($method) . ' ' . $path;
            }
        }

        sort($documented);
        sort($routed);

        self::assertNotEmpty($routed);
        self::assertSame($routed, $documented);
    }

    public function test_every_error_response_is_the_shared_problem(): void
    {
        $checked = 0;

        foreach ($this->operations() as $id => $operation) {
            foreach (self::responses($operation) as $status => $response) {
                if ($status < 400) {
                    continue;
                }

                ++$checked;
                self::assertIsArray($response, "$id $status");
                self::assertIsString($response['description'] ?? null, "$id $status has a description");
                self::assertNotSame('', trim($response['description']), "$id $status has a description");
                self::assertSame(
                    [ApiExceptionMapper::CONTENT_TYPE => ['schema' => ['$ref' => Problem::REF]]],
                    $response['content'] ?? null,
                    "$id $status is an application/problem+json Problem",
                );
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    /**
     * What an operation's shape implies it can answer: a missing resource for
     * a path with an id, a rejected input for a body or a query string, and
     * the 401 authentication adds to every versioned route.
     */
    public function test_every_operation_declares_the_errors_its_shape_implies(): void
    {
        foreach ($this->operations() as $id => $operation) {
            $statuses = array_keys(self::responses($operation));
            $ownErrors = array_filter($statuses, static fn(int $status): bool => $status >= 400 && 401 !== $status);

            self::assertContains(401, $statuses, "$id: tokens may be on");
            self::assertNotEmpty($ownErrors, "$id documents at least one error of its own");

            if (str_contains($id, '{')) {
                self::assertContains(404, $statuses, "$id has a path parameter, so the resource may not exist");
            }

            if (isset($operation['requestBody'])) {
                self::assertContains(400, $statuses, "$id takes a body, so the body may be malformed");
            }

            foreach ($operation['parameters'] ?? [] as $parameter) {
                self::assertIsArray($parameter);

                if ('query' === ($parameter['in'] ?? null)) {
                    self::assertContains(400, $statuses, "$id takes query parameters, so one may be unusable");
                }
            }

            $success = array_filter($statuses, static fn(int $status): bool => $status < 300);
            self::assertCount(1, $success, "$id documents exactly one success response");
        }
    }

    /** With every operation declaring its errors, API Platform's placeholders have nothing left to describe. */
    public function test_api_platforms_generic_error_schemas_are_gone(): void
    {
        $schemas = $this->document()['components']['schemas'] ?? null;
        self::assertIsArray($schemas);

        self::assertArrayHasKey(Problem::SCHEMA_NAME, $schemas);
        self::assertArrayNotHasKey('Error', $schemas);
        self::assertArrayNotHasKey('ConstraintViolation', $schemas);
    }

    /** The schema promises what the mapper sends: a real error has exactly the schema's members. */
    public function test_the_problem_schema_describes_a_real_error(): void
    {
        $schema = $this->document()['components']['schemas'][Problem::SCHEMA_NAME] ?? null;
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties'] ?? null);
        self::assertSame(['type', 'title', 'status', 'detail'], $schema['required'] ?? null);
        self::assertFalse($schema['additionalProperties'] ?? null);

        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => Uuid::uuid4()->toString()]));

        $this->assertProblem(404);
        self::assertSame(array_keys($schema['properties']), array_keys($this->json()));
    }

    public function test_authentication_is_documented_as_optional_with_both_headers(): void
    {
        $document = $this->document();
        $schemes = $document['components']['securitySchemes'] ?? null;
        self::assertIsArray($schemes);

        self::assertSame('http', $schemes[OpenApiFactoryDecorator::BEARER_SCHEME]['type'] ?? null);
        self::assertSame('bearer', $schemes[OpenApiFactoryDecorator::BEARER_SCHEME]['scheme'] ?? null);
        self::assertSame('apiKey', $schemes[OpenApiFactoryDecorator::API_KEY_SCHEME]['type'] ?? null);
        self::assertSame('header', $schemes[OpenApiFactoryDecorator::API_KEY_SCHEME]['in'] ?? null);
        self::assertSame(ApiTokenAuthenticator::HEADER, $schemes[OpenApiFactoryDecorator::API_KEY_SCHEME]['name'] ?? null);

        // The empty requirement is what makes a token optional; JSON's {} decodes to [].
        $security = $document['security'] ?? null;
        self::assertIsArray($security);
        self::assertContains([], $security, 'anonymous access is allowed (API_TOKENS may be empty)');
        self::assertContains([OpenApiFactoryDecorator::BEARER_SCHEME => []], $security);
        self::assertContains([OpenApiFactoryDecorator::API_KEY_SCHEME => []], $security);
    }

    public function test_the_401_response_carries_the_challenge_header(): void
    {
        $operation = $this->operations()['GET ' . self::API_PREFIX . 'carts/{id}'] ?? null;
        self::assertIsArray($operation);

        $unauthorized = self::responses($operation)[401] ?? null;
        self::assertIsArray($unauthorized);
        self::assertArrayHasKey('WWW-Authenticate', $unauthorized['headers'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $this->request('GET', '/api/docs.jsonopenapi', server: ['HTTP_ACCEPT' => 'application/vnd.openapi+json']);

        self::assertResponseStatusCodeSame(200);

        return $this->json();
    }

    /**
     * The API operations of the document, keyed "METHOD /path".
     *
     * @return array<string, array<string, mixed>>
     */
    private function operations(): array
    {
        $paths = $this->document()['paths'] ?? null;
        self::assertIsArray($paths);

        $operations = [];

        foreach ($paths as $path => $item) {
            self::assertIsString($path);
            self::assertIsArray($item);

            if (!str_starts_with($path, self::API_PREFIX)) {
                continue;
            }

            foreach (self::METHODS as $method) {
                if (!isset($item[$method])) {
                    continue;
                }

                self::assertIsArray($item[$method]);
                $operations[strtoupper($method) . ' ' . $path] = $item[$method];
            }
        }

        self::assertNotEmpty($operations);

        return $operations;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array<int, mixed> status => response
     */
    private static function responses(array $operation): array
    {
        self::assertIsArray($operation['responses'] ?? null, 'the operation documents responses');

        $responses = [];

        foreach ($operation['responses'] as $status => $response) {
            self::assertIsInt($status, 'response codes are numeric');
            $responses[$status] = $response;
        }

        return $responses;
    }
}
