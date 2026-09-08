<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Security;

use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates API calls with a static token, sent as `Authorization: Bearer
 * <token>` or `X-API-Key: <token>`.
 *
 * It only steps in when tokens are configured (API_TOKENS): with none, the
 * firewall sees a request it does not support and lets it through anonymous,
 * which is how the API has always worked. With tokens, every versioned API
 * route requires one - the documentation stays public - and a missing or
 * unknown token answers 401 in the API's own RFC 7807 shape, with the
 * `WWW-Authenticate` challenge a 401 carries.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public const HEADER = 'X-API-Key';

    public function __construct(
        private readonly ApiTokens $tokens,
        private readonly ApiExceptionMapper $errors,
        private readonly string $apiPrefix,
    ) {}

    public function supports(Request $request): bool
    {
        return $this->tokens->isEnabled()
            && str_starts_with($request->getPathInfo(), rtrim($this->apiPrefix, '/') . '/v1/');
    }

    public function authenticate(Request $request): Passport
    {
        $token = self::presentedToken($request);

        if (null === $token) {
            throw new CustomUserMessageAuthenticationException('Authentication required: send an API token as "Authorization: Bearer <token>" or "X-API-Key: <token>".');
        }

        $customer = $this->tokens->customerFor($token);

        if (null === $customer) {
            throw new BadCredentialsException('The API token is not valid.');
        }

        return new SelfValidatingPassport(new UserBadge($customer->toString(), static fn(): ApiCustomer => new ApiCustomer($customer)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Stateless: the request simply goes on to its controller.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // The security messages are written for the client (a missing or an
        // invalid token); anything else would leak nothing more than "denied".
        $detail = $exception instanceof CustomUserMessageAuthenticationException || $exception instanceof BadCredentialsException
            ? $exception->getMessage()
            : 'Authentication failed.';

        return $this->errors->toResponse(new UnauthorizedHttpException('Bearer realm="api"', $detail, $exception));
    }

    private static function presentedToken(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');

        if (\is_string($authorization) && 1 === preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) {
            return $matches[1];
        }

        $apiKey = $request->headers->get(self::HEADER);

        if (\is_string($apiKey) && '' !== trim($apiKey)) {
            return trim($apiKey);
        }

        return null;
    }
}
