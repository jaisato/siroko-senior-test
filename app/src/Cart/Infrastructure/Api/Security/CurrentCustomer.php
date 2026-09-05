<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Security;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who is calling, for the controllers: the customer behind the presented API
 * token, or nobody when the API runs without authentication.
 */
final class CurrentCustomer
{
    public function __construct(private readonly Security $security) {}

    public function idOrNull(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof ApiCustomer ? $user->customerId()->toString() : null;
    }
}
