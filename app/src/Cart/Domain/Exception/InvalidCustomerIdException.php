<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

/**
 * A customer identifier the domain will not accept. Not echoed back: it is
 * configuration (an API token's customer), never something a client typed.
 */
final class InvalidCustomerIdException extends \DomainException
{
    public static function malformed(int $maxLength): self
    {
        return new self(\sprintf('A customer id is 1 to %d printable characters without whitespace.', $maxLength));
    }
}
