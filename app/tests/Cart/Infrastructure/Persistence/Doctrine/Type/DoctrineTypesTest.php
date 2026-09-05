<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\Identifier;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\AbstractUuidType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\CartIdType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\CartStatusType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ItemIdType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ProductCodeType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ProductIdType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\ProductNameType;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Type\QuantityType;

/**
 * Round trips of the seven custom types, on both platforms the project runs on.
 */
final class DoctrineTypesTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractUuidType, class-string<Identifier>}>
     */
    public static function uuidTypes(): iterable
    {
        yield 'cart_id' => [new CartIdType(), CartId::class];
        yield 'item_id' => [new ItemIdType(), ItemId::class];
        yield 'product_id' => [new ProductIdType(), ProductId::class];
    }

    /**
     * @param class-string<Identifier> $class
     */
    #[DataProvider('uuidTypes')]
    public function test_uuid_types_store_sixteen_bytes_and_read_the_identifier_back(AbstractUuidType $type, string $class): void
    {
        $platform = new SqlitePlatform();
        $id = $class::fromString(Uuid::uuid4()->toString());

        $stored = $type->convertToDatabaseValue($id, $platform);

        self::assertIsString($stored);
        self::assertSame(16, \strlen($stored));
        self::assertSame($stored, $type->convertToDatabaseValue($id->toString(), $platform), 'a plain string is accepted too');

        $read = $type->convertToPHPValue($stored, $platform);

        self::assertInstanceOf($class, $read);
        self::assertSame($id->toString(), $read->toString());
        self::assertSame($id, $type->convertToPHPValue($id, $platform), 'an identifier passes through');
        self::assertSame($id->toString(), $type->convertToPHPValue($id->toString(), $platform)?->toString(), 'a textual uuid is understood as well');
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    /**
     * @param class-string<Identifier> $class
     */
    #[DataProvider('uuidTypes')]
    public function test_uuid_types_declare_a_fixed_binary_column_on_each_platform(AbstractUuidType $type, string $class): void
    {
        self::assertSame('BINARY(16)', $type->getSQLDeclaration([], new MySQLPlatform()));
        self::assertSame('BLOB', $type->getSQLDeclaration([], new SqlitePlatform()));
        self::assertSame(ParameterType::BINARY, $type->getBindingType());
        self::assertInstanceOf($class, $type->convertToPHPValue(Uuid::uuid4()->getBytes(), new MySQLPlatform()));
    }

    /**
     * @param class-string<Identifier> $class
     */
    #[DataProvider('uuidTypes')]
    public function test_uuid_types_refuse_values_that_are_not_uuids(AbstractUuidType $type, string $class): void
    {
        $this->expectException(ConversionException::class);

        $type->convertToDatabaseValue('not-a-uuid', new SqlitePlatform());
    }

    /**
     * @param class-string<Identifier> $class
     */
    #[DataProvider('uuidTypes')]
    public function test_uuid_types_refuse_values_of_the_wrong_kind(AbstractUuidType $type, string $class): void
    {
        $this->expectException(ConversionException::class);

        $type->convertToDatabaseValue(42, new SqlitePlatform());
    }

    /**
     * @return iterable<string, array{AbstractPlatform, string}>
     */
    public static function platforms(): iterable
    {
        yield 'mysql' => [new MySQLPlatform(), 'INT'];
        yield 'sqlite' => [new SqlitePlatform(), 'INTEGER'];
    }

    #[DataProvider('platforms')]
    public function test_cart_status_round_trips_as_an_integer(AbstractPlatform $platform, string $declaration): void
    {
        $type = new CartStatusType();

        self::assertSame($declaration, $type->getSQLDeclaration([], $platform));
        self::assertSame(ParameterType::INTEGER, $type->getBindingType());
        self::assertSame(CartStatus::PAID, $type->convertToDatabaseValue(CartStatus::paid(), $platform));
        self::assertSame(CartStatus::PAID, $type->convertToDatabaseValue(CartStatus::PAID, $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));

        $read = $type->convertToPHPValue(2, $platform);
        self::assertInstanceOf(CartStatus::class, $read);
        self::assertSame(CartStatus::PAID, $read->toInt());
        self::assertInstanceOf(CartStatus::class, $type->convertToPHPValue('1', $platform), 'drivers may hand integers back as strings');
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    public function test_cart_status_refuses_a_value_that_is_not_a_status(): void
    {
        $this->expectException(ConversionException::class);

        (new CartStatusType())->convertToDatabaseValue('paid', new SqlitePlatform());
    }

    #[DataProvider('platforms')]
    public function test_quantity_round_trips_as_an_integer(AbstractPlatform $platform, string $declaration): void
    {
        $type = new QuantityType();

        self::assertSame($declaration, $type->getSQLDeclaration([], $platform));
        self::assertSame(ParameterType::INTEGER, $type->getBindingType());
        self::assertSame(7, $type->convertToDatabaseValue(new Quantity(7), $platform));
        self::assertSame(7, $type->convertToDatabaseValue(7, $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));

        $read = $type->convertToPHPValue('7', $platform);
        self::assertInstanceOf(Quantity::class, $read);
        self::assertSame(7, $read->asInt());
        self::assertSame(0, $type->convertToPHPValue(0, $platform)?->asInt());
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    public function test_quantity_refuses_a_value_that_is_not_a_number(): void
    {
        $this->expectException(ConversionException::class);

        (new QuantityType())->convertToDatabaseValue('seven', new SqlitePlatform());
    }

    public function test_product_name_round_trips_as_a_varchar_of_its_max_length(): void
    {
        $type = new ProductNameType();

        self::assertSame('VARCHAR(200)', $type->getSQLDeclaration([], new MySQLPlatform()));
        self::assertSame('VARCHAR(120)', $type->getSQLDeclaration(['length' => 120], new MySQLPlatform()));
        self::assertSame('product_name', $type->getName());

        $name = Name::fromString('Gafas Siroko');
        self::assertSame('Gafas Siroko', $type->convertToDatabaseValue($name, new SqlitePlatform()));
        self::assertSame('Gafas Siroko', $type->convertToDatabaseValue('Gafas Siroko', new SqlitePlatform()));

        $read = $type->convertToPHPValue('Gafas Siroko', new SqlitePlatform());
        self::assertInstanceOf(Name::class, $read);
        self::assertSame('Gafas Siroko', $read->toString());
        self::assertSame($name, $type->convertToPHPValue($name, new SqlitePlatform()));
        self::assertNull($type->convertToPHPValue(null, new SqlitePlatform()));
        self::assertNull($type->convertToDatabaseValue(null, new SqlitePlatform()));
    }

    /**
     * A row that no longer satisfies today's rules still loads: the rules
     * belong to the write path.
     */
    public function test_string_types_do_not_revalidate_on_hydration(): void
    {
        $tooShort = (new ProductNameType())->convertToPHPValue('ab', new SqlitePlatform());
        self::assertInstanceOf(Name::class, $tooShort);
        self::assertSame('ab', $tooShort->toString());

        $untrimmed = (new ProductCodeType())->convertToPHPValue(' SKU ', new SqlitePlatform());
        self::assertInstanceOf(ProductCode::class, $untrimmed);
        self::assertSame(' SKU ', $untrimmed->toString());
    }

    public function test_product_code_round_trips_as_a_varchar_of_its_max_length(): void
    {
        $type = new ProductCodeType();

        self::assertSame('VARCHAR(50)', $type->getSQLDeclaration([], new MySQLPlatform()));
        self::assertSame('product_code', $type->getName());

        $code = ProductCode::fromString('SKU-1');
        self::assertSame('SKU-1', $type->convertToDatabaseValue($code, new MySQLPlatform()));

        $read = $type->convertToPHPValue('SKU-1', new MySQLPlatform());
        self::assertInstanceOf(ProductCode::class, $read);
        self::assertTrue($code->equals($read));
    }

    public function test_string_types_refuse_values_of_the_wrong_kind(): void
    {
        $this->expectException(ConversionException::class);

        (new ProductCodeType())->convertToDatabaseValue(new \stdClass(), new SqlitePlatform());
    }

    public function test_type_names_match_the_doctrine_registration(): void
    {
        self::assertSame('cart_id', (new CartIdType())->getName());
        self::assertSame('item_id', (new ItemIdType())->getName());
        self::assertSame('product_id', (new ProductIdType())->getName());
        self::assertSame('cart_status', (new CartStatusType())->getName());
        self::assertSame('quantity', (new QuantityType())->getName());
    }
}
