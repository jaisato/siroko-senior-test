<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;
use Siroko\Cart\Infrastructure\Api\Security\ApiTokenAuthenticator;

/**
 * Adds to the generated document what the resource attributes cannot say.
 *
 * Each `#[ApiResource]` declares, per operation, the error responses that
 * operation can answer. Three things cut across all of them and live here
 * instead: the shared `Problem` schema those responses reference, the way a
 * caller authenticates (two header schemes, both optional because API_TOKENS
 * may be empty), and the 401 every versioned route answers when tokens are
 * on and none is presented. Repeating that 401 in every attribute would say
 * nothing the firewall does not already decide for all of them at once.
 */
final class OpenApiFactoryDecorator implements OpenApiFactoryInterface
{
    public const BEARER_SCHEME = 'bearerAuth';

    public const API_KEY_SCHEME = 'apiKeyAuth';

    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
        private readonly string $apiPrefix,
    ) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $components = $openApi->getComponents();

        $schemas = $components->getSchemas() ?? new \ArrayObject();
        $schemas[Problem::SCHEMA_NAME] = new \ArrayObject(Problem::SCHEMA);

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

        return $openApi
            ->withComponents($components->withSchemas($schemas)->withSecuritySchemes($securitySchemes))
            ->withPaths($paths)
            // Alternatives: no credentials at all (API_TOKENS empty), or a token
            // by either header. The empty requirement - an object, not a list,
            // which is why it is an ArrayObject - is what makes them optional.
            ->withSecurity([new \ArrayObject(), [self::BEARER_SCHEME => []], [self::API_KEY_SCHEME => []]]);
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
