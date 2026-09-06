<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Security;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\Security\ApiCustomer;
use Siroko\Cart\Infrastructure\Api\Security\ApiTokenAuthenticator;
use Siroko\Cart\Infrastructure\Api\Security\ApiTokens;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final class ApiTokenAuthenticatorTest extends TestCase
{
    /** With no tokens configured the authenticator steps aside and every request stays anonymous. */
    public function test_it_supports_nothing_while_authentication_is_off(): void
    {
        $authenticator = $this->authenticator(ApiTokens::none());

        self::assertFalse($authenticator->supports(Request::create('/api/v1/carts')));
        self::assertFalse($authenticator->supports(Request::create('/api/v1/carts', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer alice-token'])));
    }

    public function test_it_supports_the_versioned_api_only(): void
    {
        $authenticator = $this->authenticator(ApiTokens::fromSpec('alice-token:alice'));

        self::assertTrue($authenticator->supports(Request::create('/api/v1/carts')));
        self::assertTrue($authenticator->supports(Request::create('/api/v1/products/by-code/K3')));
        self::assertFalse($authenticator->supports(Request::create('/api/docs')), 'the documentation stays public');
        self::assertFalse($authenticator->supports(Request::create('/api/docs.jsonopenapi')));
        self::assertFalse($authenticator->supports(Request::create('/health')));
    }

    public function test_a_bearer_token_identifies_its_customer(): void
    {
        $authenticator = $this->authenticator(ApiTokens::fromSpec('alice-token:alice'));

        $passport = $authenticator->authenticate(Request::create('/api/v1/carts', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer alice-token']));

        $badge = $passport->getBadge(UserBadge::class);
        self::assertInstanceOf(UserBadge::class, $badge);
        self::assertSame('alice', $badge->getUserIdentifier());
        $user = $badge->getUser();
        self::assertInstanceOf(ApiCustomer::class, $user);
        self::assertSame('alice', $user->customerId()->toString());
        self::assertSame('alice', $user->getUserIdentifier());
        self::assertSame([ApiCustomer::ROLE], $user->getRoles());
    }

    public function test_an_api_key_header_works_as_well(): void
    {
        $authenticator = $this->authenticator(ApiTokens::fromSpec('alice-token:alice'));

        $passport = $authenticator->authenticate(Request::create('/api/v1/carts', 'GET', server: ['HTTP_X_API_KEY' => ' alice-token ']));

        self::assertSame('alice', $passport->getBadge(UserBadge::class)?->getUserIdentifier());
    }

    public function test_a_missing_token_is_refused_with_an_explanation(): void
    {
        $authenticator = $this->authenticator(ApiTokens::fromSpec('alice-token:alice'));

        try {
            $authenticator->authenticate(Request::create('/api/v1/carts'));
            self::fail('expected an exception');
        } catch (CustomUserMessageAuthenticationException $e) {
            self::assertStringContainsString('Authorization: Bearer', $e->getMessage());
        }

        $this->expectException(CustomUserMessageAuthenticationException::class);

        // A non-Bearer scheme is not a token of ours.
        $authenticator->authenticate(Request::create('/api/v1/carts', 'GET', server: ['HTTP_AUTHORIZATION' => 'Basic YWxpY2U6cHc=']));
    }

    public function test_an_unknown_token_is_bad_credentials(): void
    {
        $authenticator = $this->authenticator(ApiTokens::fromSpec('alice-token:alice'));

        $this->expectException(BadCredentialsException::class);

        $authenticator->authenticate(Request::create('/api/v1/carts', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong']));
    }

    /** Failures answer in the API's own error shape, with the challenge a 401 carries. */
    public function test_a_failure_is_a_401_problem_with_a_challenge(): void
    {
        $authenticator = $this->authenticator(ApiTokens::fromSpec('alice-token:alice'));

        $response = $authenticator->onAuthenticationFailure(Request::create('/api/v1/carts'), new BadCredentialsException('The API token is not valid.'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('Bearer realm="api"', $response->headers->get('WWW-Authenticate'));
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(401, $body['status']);
        self::assertSame('The API token is not valid.', $body['detail']);

        $generic = $authenticator->onAuthenticationFailure(Request::create('/api/v1/carts'), new \Symfony\Component\Security\Core\Exception\AuthenticationServiceException('database is down at db:3306'));
        self::assertStringNotContainsString('db:3306', (string) $generic->getContent(), 'internal failures are not described to the client');
    }

    public function test_success_lets_the_request_continue(): void
    {
        $authenticator = $this->authenticator(ApiTokens::fromSpec('alice-token:alice'));

        self::assertNull($authenticator->onAuthenticationSuccess(Request::create('/api/v1/carts'), $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class), 'main'));
    }

    private function authenticator(ApiTokens $tokens): ApiTokenAuthenticator
    {
        return new ApiTokenAuthenticator($tokens, new ApiExceptionMapper(new NullLogger()), '/api');
    }
}
