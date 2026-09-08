<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Type;

use Siroko\Cart\Domain\ValueObject\CustomerId;

final class CustomerIdType extends AbstractStringValueObjectType
{
    public const NAME = 'customer_id';

    protected function voClass(): string
    {
        return CustomerId::class;
    }

    protected function typeName(): string
    {
        return self::NAME;
    }

    protected function defaultLength(): int
    {
        return CustomerId::MAX_LENGTH;
    }
}
