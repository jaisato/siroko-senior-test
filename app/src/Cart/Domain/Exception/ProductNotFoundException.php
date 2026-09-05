<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

use Siroko\Cart\Domain\ValueObject\ProductId;

/**
 * No product exists with the requested identifier.
 *
 * Handlers used to throw Symfony's NotFoundHttpException for this, which tied
 * the application layer to the HTTP kernel; the status code is the API's
 * concern and lives in the exception mapper.
 */
final class ProductNotFoundException extends \DomainException
{
    public static function withId(ProductId $id): self
    {
        return new self(\sprintf('Product %s not found.', $id->toString()));
    }
}
