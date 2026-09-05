<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

use Siroko\Cart\Domain\ValueObject\OrderId;

/**
 * No order exists with the requested identifier.
 */
final class OrderNotFoundException extends \DomainException
{
    public static function withId(OrderId $id): self
    {
        return new self(\sprintf('Order %s not found.', $id->toString()));
    }
}
