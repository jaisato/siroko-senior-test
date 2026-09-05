<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\Identifier;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\ProductId;

/**
 * CartId, ItemId and ProductId share one shape: a UUID, validated on construction.
 */
final class IdentifierTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<Identifier>}>
     */
    public static function identifiers(): iterable
    {
        yield 'cart' => [CartId::class];
        yield 'item' => [ItemId::class];
        yield 'product' => [ProductId::class];
    }

    /**
     * @param class-string<Identifier> $class
     */
    #[DataProvider('identifiers')]
    public function test_a_uuid_is_kept_in_canonical_lowercase_form(string $class): void
    {
        $uuid = Uuid::uuid4()->toString();

        $id = $class::fromString(strtoupper($uuid));

        self::assertSame($uuid, $id->toString());
        self::assertSame($uuid, (string) $id);
    }

    public function test_equality_is_by_value(): void
    {
        $uuid = Uuid::uuid4()->toString();
        $other = Uuid::uuid4()->toString();

        self::assertTrue(CartId::fromString($uuid)->equals(CartId::fromString(strtoupper($uuid))));
        self::assertTrue(ItemId::fromString($uuid)->equals(ItemId::fromString(strtoupper($uuid))));
        self::assertTrue(ProductId::fromString($uuid)->equals(ProductId::fromString(strtoupper($uuid))));

        self::assertFalse(CartId::fromString($uuid)->equals(CartId::fromString($other)));
        self::assertFalse(ItemId::fromString($uuid)->equals(ItemId::fromString($other)));
        self::assertFalse(ProductId::fromString($uuid)->equals(ProductId::fromString($other)));
    }

    /**
     * Any string used to be accepted and the Doctrine type blew up while binding
     * the query parameter - a 500 for a malformed request.
     *
     * @param class-string<Identifier> $class
     */
    #[DataProvider('identifiers')]
    public function test_a_value_that_is_not_a_uuid_is_rejected_without_being_echoed(string $class): void
    {
        try {
            $class::fromString('not-a-uuid');
            self::fail('expected an exception');
        } catch (InvalidIdentifierException $e) {
            self::assertStringNotContainsString('not-a-uuid', $e->getMessage());
            self::assertStringContainsString('UUID', $e->getMessage());
            self::assertStringContainsString(substr($class, strrpos($class, '\\') + 1), $e->getMessage(), 'the message names the kind of identifier');
        }
    }

    /**
     * @param class-string<Identifier> $class
     */
    #[DataProvider('identifiers')]
    public function test_the_empty_string_is_rejected(string $class): void
    {
        $this->expectException(InvalidIdentifierException::class);

        $class::fromString('');
    }
}
