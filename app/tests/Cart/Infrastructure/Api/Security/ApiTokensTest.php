<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Infrastructure\Api\Security\ApiTokens;

/**
 * API_TOKENS parsing: off when empty, `token:customerId` pairs otherwise, and
 * a malformed value fails fast rather than letting everybody in.
 */
final class ApiTokensTest extends TestCase
{
    public function test_an_empty_value_means_authentication_is_off(): void
    {
        self::assertFalse(ApiTokens::fromSpec('')->isEnabled());
        self::assertFalse(ApiTokens::fromSpec('  , ,')->isEnabled(), 'separators alone configure nothing');
        self::assertFalse(ApiTokens::none()->isEnabled());
        self::assertNull(ApiTokens::fromSpec('')->customerFor('anything'));
    }

    public function test_it_maps_each_token_to_its_customer(): void
    {
        $tokens = ApiTokens::fromSpec(' alice-token:alice , bob-token:bob@example.test ');

        self::assertTrue($tokens->isEnabled());
        self::assertSame('alice', $tokens->customerFor('alice-token')?->toString());
        self::assertSame('bob@example.test', $tokens->customerFor('bob-token')?->toString());
        self::assertNull($tokens->customerFor('unknown'));
        self::assertNull($tokens->customerFor('alice-toke'), 'a prefix is not the token');
        self::assertNull($tokens->customerFor(''), 'an empty token never matches');
    }

    /** The customer id follows the last colon, so a token may contain colons itself. */
    public function test_a_token_may_contain_colons(): void
    {
        $tokens = ApiTokens::fromSpec('sk:live:abc123:alice');

        self::assertSame('alice', $tokens->customerFor('sk:live:abc123')?->toString());
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_value_is_a_configuration_error(string $spec, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ApiTokens::fromSpec($spec);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformed(): iterable
    {
        yield 'no separator' => ['alice-token', 'token:customerId'];
        yield 'empty token' => [':alice', 'token:customerId'];
        yield 'empty customer' => ['alice-token:', 'token:customerId'];
        yield 'duplicate token' => ['t:alice,t:bob', 'twice'];
        yield 'customer with a space' => ['t:alice smith', 'customer id'];
    }
}
