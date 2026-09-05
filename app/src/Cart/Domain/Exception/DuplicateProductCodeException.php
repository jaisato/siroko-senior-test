<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

use Siroko\Cart\Domain\ValueObject\ProductCode;

/**
 * A product with the same code already exists.
 *
 * The code is the business key of a product; two products sharing one would be
 * indistinguishable to whoever reads a cart. The handler checks first and the
 * database enforces it with a unique index, so a lost race still answers 409.
 */
final class DuplicateProductCodeException extends \DomainException
{
    public static function forCode(ProductCode $code): self
    {
        return new self(\sprintf('A product with code "%s" already exists.', $code->toString()));
    }
}
