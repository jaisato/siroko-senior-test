<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Domain\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Domain\Exception\InvalidCustomerIdException;
use Siroko\Cart\Domain\ValueObject\CustomerId;

final class CustomerIdTest extends TestCase
{
    #[DataProvider('identifiers')]
    public function test_it_holds_a_short_opaque_identifier(string $value): void
    {
        $id = CustomerId::fromString($value);

        self::assertSame($value, $id->toString());
        self::assertSame($value, (string) $id);
        self::assertTrue($id->equals(CustomerId::fromString($value)));
        self::assertFalse($id->equals(CustomerId::fromString('somebody-else')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function identifiers(): iterable
    {
        yield 'word' => ['alice'];
        yield 'email-like' => ['alice@example.test'];
        yield 'uuid' => ['018f9f3b-8d18-7d73-9b86-9a4f2e6f5e9a'];
        yield 'one character' => ['a'];
        yield 'at the limit' => [str_repeat('x', CustomerId::MAX_LENGTH)];
    }

    #[DataProvider('rejected')]
    public function test_it_refuses_values_that_are_not_identifiers(string $value): void
    {
        try {
            CustomerId::fromString($value);
            self::fail('expected an exception');
        } catch (InvalidCustomerIdException $e) {
            if ('' !== $value) {
                self::assertStringNotContainsString($value, $e->getMessage(), 'the value is not echoed');
            }
            self::assertStringContainsString((string) CustomerId::MAX_LENGTH, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'space inside' => ['alice smith'];
        yield 'leading space' => [' alice'];
        yield 'control character' => ["alice\n"];
        yield 'non-ascii' => ['alícia'];
        yield 'too long' => [str_repeat('x', CustomerId::MAX_LENGTH + 1)];
    }

    /** Rows are rebuilt without re-applying the write-path rules. */
    public function test_from_persistence_does_not_revalidate(): void
    {
        self::assertSame('legacy id', CustomerId::fromPersistence('legacy id')->toString());
    }
}
