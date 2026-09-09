<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;
use Siroko\Cart\Infrastructure\Api\Security\ApiTokenAuthenticator;
use Siroko\Cart\Infrastructure\Api\Security\ApiTokens;
use Siroko\Cart\Infrastructure\Health\DatabaseProbe;
use Siroko\Cart\Infrastructure\Health\HealthReport;
use Siroko\Cart\Infrastructure\Health\MessengerProbe;

/**
 * Adds to the generated document what the resource attributes cannot say.
 *
 * Each `#[ApiResource]` declares, per operation, the error responses that
 * operation can answer. Three things cut across all of them and live here
 * instead: the shared `Problem` schema those responses reference, the way a
 * caller authenticates (two header schemes, and no credentials at all only
 * while API_TOKENS is empty - the document describes the deployment that
 * serves it), and the 401 every versioned route answers when tokens are on
 * and none is presented. Repeating that 401 in every attribute would say
 * nothing the firewall does not already decide for all of them at once.
 *
 * GET /health is documented here as well: it is a plain Symfony route, not an
 * API Platform resource, and the factory would not see it otherwise.
 */
final class OpenApiFactoryDecorator implements OpenApiFactoryInterface
{
    public const BEARER_SCHEME = 'bearerAuth';

    public const API_KEY_SCHEME = 'apiKeyAuth';

    public const HEALTH_PATH = '/health';

    public const HEALTH_SCHEMA_NAME = 'Health';

    private const HEALTH_TAG = 'Health';

    /**
     * @var array<string, mixed>
     */
    private const HEALTH_SCHEMA = [
        'type' => 'object',
        'description' => 'The verdict of every dependency check. Only verdicts: the reason a check failed is in the log.',
        'required' => ['status', 'checks'],
        'additionalProperties' => false,
        'properties' => [
            'status' => [
                'type' => 'string',
                'enum' => [HealthReport::OK, HealthReport::FAIL],
                'description' => '"ok" when every check passed (HTTP 200), "fail" otherwise (HTTP 503).',
            ],
            'checks' => [
                'type' => 'object',
                'description' => 'One entry per check, by name.',
                'additionalProperties' => ['type' => 'string', 'enum' => [HealthReport::OK, HealthReport::FAIL]],
                'examples' => [[DatabaseProbe::NAME => HealthReport::OK, MessengerProbe::NAME => HealthReport::OK]],
            ],
        ],
    ];

    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
        private readonly ApiTokens $tokens,
        private readonly string $apiPrefix,
    ) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $components = $openApi->getComponents();

        $schemas = $components->getSchemas() ?? new \ArrayObject();
        $schemas[Problem::SCHEMA_NAME] = new \ArrayObject(Problem::SCHEMA);
        $schemas[self::HEALTH_SCHEMA_NAME] = new \ArrayObject(self::HEALTH_SCHEMA);

        $securitySchemes = $components->getSecuritySchemes() ?? new \ArrayObject();
        $securitySchemes[self::BEARER_SCHEME] = new Model\SecurityScheme(
            type: 'http',
            description: 'An API token from API_TOKENS, presented as "Authorization: Bearer <token>".',
            scheme: 'bearer',
        );
        $securitySchemes[self::API_KEY_SCHEME] = new Model\SecurityScheme(
            type: 'apiKey',
            description: 'The same token, presented as the X-API-Key header.',
            name: ApiTokenAuthenticator::HEADER,
            in: 'header',
        );

        $paths = new Model\Paths();

        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            \assert(\is_string($path) && $pathItem instanceof Model\PathItem);

            $paths->addPath($path, self::withTidyResponses($pathItem, $this->isVersioned($path)));
        }

        $paths->addPath(self::HEALTH_PATH, self::healthPathItem());

        return $openApi
            ->withComponents($components->withSchemas($schemas)->withSecuritySchemes($securitySchemes))
            ->withPaths($paths)
            ->withTags([...$openApi->getTags(), new Model\Tag(self::HEALTH_TAG, 'The health of the service: the checks the container healthchecks rely on.')])
            ->withSecurity($this->security());
    }

    /**
     * The alternatives a caller has: a token by either header and, while
     * API_TOKENS is empty, no credentials at all. The empty requirement - an
     * object, not a list, which is why it is an ArrayObject - is what says
     * anonymous access is allowed, and it is left out as soon as tokens are
     * on: `security` says which credential to send, and a client generated
     * from a protected deployment's document that believed it could send
     * nothing sent nothing and got 401 on every call. The schemes themselves
     * stay whatever the setting: they say how a token is presented, not
     * whether one is needed.
     *
     * @return list<\ArrayObject<string, mixed>|array<string, list<string>>>
     */
    private function security(): array
    {
        $withToken = [[self::BEARER_SCHEME => []], [self::API_KEY_SCHEME => []]];

        return $this->tokens->isEnabled() ? $withToken : [new \ArrayObject(), ...$withToken];
    }

    /**
     * The routes the authenticator guards; see ApiTokenAuthenticator::supports().
     */
    private function isVersioned(string $path): bool
    {
        return str_starts_with($path, rtrim($this->apiPrefix, '/') . '/v1/');
    }

    /**
     * @param bool $guarded whether the authenticator guards the path, so its operations answer 401
     */
    private static function withTidyResponses(Model\PathItem $pathItem, bool $guarded): Model\PathItem
    {
        return $pathItem
            ->withGet(self::tidy($pathItem->getGet(), $guarded))
            ->withPost(self::tidy($pathItem->getPost(), $guarded))
            ->withPut(self::tidy($pathItem->getPut(), $guarded))
            ->withPatch(self::tidy($pathItem->getPatch(), $guarded))
            ->withDelete(self::tidy($pathItem->getDelete(), $guarded));
    }

    /**
     * Adds the 401 to a guarded operation that does not declare its own, and
     * orders the responses by status: API Platform appends the success
     * response after the declared errors, which read backwards in the UI.
     */
    private static function tidy(?Model\Operation $operation, bool $guarded): ?Model\Operation
    {
        if (null === $operation) {
            return null;
        }

        $responses = $operation->getResponses() ?? [];

        if ($guarded && !isset($responses[401])) {
            $responses[401] = self::unauthorized();
        }

        ksort($responses);

        return $operation->withResponses($responses);
    }

    private static function healthPathItem(): Model\PathItem
    {
        $content = new \ArrayObject([
            'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . self::HEALTH_SCHEMA_NAME]],
        ]);

        return new Model\PathItem(
            get: new Model\Operation(
                operationId: 'health',
                tags: [self::HEALTH_TAG],
                responses: [
                    200 => new Model\Response('Every check passed.', $content),
                    503 => new Model\Response('At least one check failed; the body says which, the log says why.', $content),
                ],
                summary: 'Health of the service',
                description: 'Runs the checks the container healthchecks rely on: the database answers a trivial query, and the message transport can be asked for its queue length (with the Doctrine transport, `messenger_messages` is reachable). Public even when API_TOKENS is set, never cached. `bin/console app:health` runs the same checks with exit code 0 or 1.',
                // No token, ever: the authenticator does not guard this path.
                security: [],
            ),
        );
    }

    private static function unauthorized(): Model\Response
    {
        return new Model\Response(
            Problem::UNAUTHENTICATED,
            new \ArrayObject(Problem::CONTENT),
            new \ArrayObject([
                'WWW-Authenticate' => [
                    'description' => 'The challenge a 401 carries.',
                    'schema' => ['type' => 'string', 'examples' => ['Bearer realm="api"']],
                ],
            ]),
        );
    }
}
