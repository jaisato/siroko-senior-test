<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\ValueObject;

final class OrderId implements Identifier
{
    use UuidIdentity;
}
