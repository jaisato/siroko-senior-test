<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

use Siroko\Cart\Domain\ValueObject\CartId;

/**
 * No cart exists with the requested identifier.
 *
 * Read handlers used to trust a `@var Cart` annotation over the nullable
 * return type of the repository, so an unknown id reached `CartRead::fromModel()`
 * as null and the client got a 500 for asking about a cart that is not there.
 */
final class CartNotFoundException extends \DomainException
{
    public static function withId(CartId $id): self
    {
        return new self(\sprintf('Cart %s not found.', $id->toString()));
    }
}
