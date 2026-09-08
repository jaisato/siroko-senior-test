<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Product;

use Siroko\Cart\Domain\Exception\InvalidProductCodeException;
use Siroko\Cart\Domain\ValueObject\ProductCode;

final class GetProductByCodeQuery
{
    private readonly ProductCode $code;

    /**
     * @throws InvalidProductCodeException
     */
    public function __construct(string $code)
    {
        $this->code = ProductCode::fromString($code);
    }

    public function code(): ProductCode
    {
        return $this->code;
    }
}
