<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

final class ProductId implements Identifier
{
    use UuidIdentity;
}
