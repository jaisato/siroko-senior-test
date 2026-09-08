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

    /**
     * A customer id is any printable ASCII, colons included, so an entry with
     * two of them could be read either way. Read from the last, `t:acme:alice`
     * is the token `t:acme` for `alice`, and the credential the deployment
     * meant - `t` - is refused for the life of the deployment. Read from the
     * first it is `t` for `acme:alice`, which is the same misreading pointing
     * the other way: a secret written with a colon in it would be registered as
     * the part before it, and that prefix would open the API. Neither is
     * something to pick; the entry is refused.
     */
    public function test_an_entry_that_could_be_read_two_ways_is_refused(): void
    {
        foreach (['t:acme:alice', 'sk:live:abc123:alice', 't::alice'] as $spec) {
            try {
                ApiTokens::fromSpec($spec);
                self::fail(\sprintf('"%s" has no single separator and must not be guessed at.', $spec));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('which of them separates', $e->getMessage());
            }
        }
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
