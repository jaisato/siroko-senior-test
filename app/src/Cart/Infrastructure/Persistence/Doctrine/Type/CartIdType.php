<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Siroko\Cart\Domain\ValueObject\CartId;

final class CartIdType extends AbstractUuidType
{
    public const NAME = 'cart_id';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function identifierClass(): string
    {
        return CartId::class;
    }
}
