<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Siroko\Cart\Domain\ValueObject\OrderId;

final class OrderIdType extends AbstractUuidType
{
    public const NAME = 'order_id';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function identifierClass(): string
    {
        return OrderId::class;
    }
}
