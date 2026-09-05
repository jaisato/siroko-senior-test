<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Siroko\Cart\Domain\ValueObject\ProductId;

final class ProductIdType extends AbstractUuidType
{
    public const NAME = 'product_id';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function identifierClass(): string
    {
        return ProductId::class;
    }
}
