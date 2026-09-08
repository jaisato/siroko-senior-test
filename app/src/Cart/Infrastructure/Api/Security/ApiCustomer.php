<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Security;

use Siroko\Cart\Domain\ValueObject\CustomerId;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The authenticated caller, as the security component sees it: a customer id
 * behind an API token. There is no password and no profile; the identity is
 * the token's.
 */
final class ApiCustomer implements UserInterface
{
    public const ROLE = 'ROLE_CUSTOMER';

    public function __construct(private readonly CustomerId $customerId) {}

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    /**
     * @return non-empty-list<string>
     */
    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void {}

    public function getUserIdentifier(): string
    {
        $identifier = $this->customerId->toString();

        // CustomerId::fromString() refuses the empty string; the guard is what
        // tells the type system the identifier is never empty.
        if ('' === $identifier) {
            throw new \LogicException('A customer id is never empty.');
        }

        return $identifier;
    }
}
