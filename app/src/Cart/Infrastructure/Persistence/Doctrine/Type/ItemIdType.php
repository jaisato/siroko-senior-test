<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Siroko\Cart\Domain\ValueObject\ItemId;

final class ItemIdType extends AbstractUuidType
{
    public const NAME = 'item_id';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function identifierClass(): string
    {
        return ItemId::class;
    }
}
