<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Security;

use Siroko\Cart\Domain\Exception\InvalidCustomerIdException;
use Siroko\Cart\Domain\ValueObject\CustomerId;

/**
 * The API keys the deployment accepts, from the API_TOKENS environment
 * variable: `token:customerId` pairs separated by commas, for instance
 * `s3cret-for-alice:alice,s3cret-for-bob:bob`.
 *
 * An empty variable means authentication is off, which is the default: the
 * documented try-it-out flow works on a fresh clone with no tokens at all,
 * and a deployment that wants callers identified sets the variable.
 *
 * A malformed value is a deployment error and fails fast, when the container
 * first needs the tokens, rather than silently letting everybody in.
 */
final class ApiTokens
{
    /**
     * @param array<string, CustomerId> $customersByToken
     */
    private function __construct(private readonly array $customersByToken) {}

    /**
     * @throws \InvalidArgumentException when the value is not a list of `token:customerId` pairs
     */
    public static function fromSpec(string $spec): self
    {
        $customersByToken = [];

        foreach (explode(',', $spec) as $entry) {
            $entry = trim($entry);

            if ('' === $entry) {
                continue;
            }

            $separator = strrpos($entry, ':');

            if (false === $separator || 0 === $separator || $separator === \strlen($entry) - 1) {
                throw new \InvalidArgumentException('API_TOKENS must be a comma-separated list of "token:customerId" pairs.');
            }

            $token = substr($entry, 0, $separator);

            if (isset($customersByToken[$token])) {
                throw new \InvalidArgumentException('API_TOKENS lists the same token twice.');
            }

            try {
                $customersByToken[$token] = CustomerId::fromString(substr($entry, $separator + 1));
            } catch (InvalidCustomerIdException $e) {
                throw new \InvalidArgumentException('API_TOKENS holds a malformed customer id: ' . $e->getMessage(), 0, $e);
            }
        }

        return new self($customersByToken);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Authentication is on as soon as one token is configured.
     */
    public function isEnabled(): bool
    {
        return [] !== $this->customersByToken;
    }

    /**
     * The customer a presented token identifies, or null for an unknown
     * token. Compared in constant time: a timing difference must not tell an
     * attacker how many leading characters were right.
     */
    public function customerFor(string $token): ?CustomerId
    {
        foreach ($this->customersByToken as $known => $customer) {
            if (hash_equals((string) $known, $token)) {
                return $customer;
            }
        }

        return null;
    }
}
