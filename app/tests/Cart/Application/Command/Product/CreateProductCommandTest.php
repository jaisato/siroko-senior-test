<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Product;

use PHPUnit\Framework\TestCase;
use Siroko\Cart\Application\Command\Product\CreateProductCommand;
use Siroko\Cart\Domain\Exception\InvalidPriceException;
use Siroko\Cart\Domain\Exception\InvalidProductCodeException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Exception\NameInvalidLengthException;

/**
 * Building the command is validating it: each field is a value object.
 */
final class CreateProductCommandTest extends TestCase
{
    public function test_a_valid_command_exposes_its_value_objects(): void
    {
        $command = new CreateProductCommand(' K3 ', 'Gafas Siroko', '129.95', 'EUR', '12');

        self::assertSame('K3', $command->getCode()->toString(), 'codes are trimmed');
        self::assertSame('Gafas Siroko', $command->getName()->toString());
        self::assertSame('129.95', $command->getPrice()->amount());
        self::assertSame('EUR', $command->getPrice()->currency()->getCurrencyCode());
        self::assertSame(12, $command->getQuantity()->asInt());
    }

    public function test_a_bad_code_is_rejected(): void
    {
        $this->expectException(InvalidProductCodeException::class);

        new CreateProductCommand('', 'Gafas Siroko', '129.95', 'EUR', 1);
    }

    public function test_a_bad_name_is_rejected(): void
    {
        $this->expectException(NameInvalidLengthException::class);

        new CreateProductCommand('K3', 'ab', '129.95', 'EUR', 1);
    }

    /** brick's exceptions used to escape as a 500; now the domain speaks. */
    public function test_a_bad_price_is_rejected(): void
    {
        $this->expectException(InvalidPriceException::class);

        new CreateProductCommand('K3', 'Gafas Siroko', '129.95', 'XYZ', 1);
    }

    public function test_a_bad_quantity_is_rejected(): void
    {
        $this->expectException(InvalidQuantityException::class);

        new CreateProductCommand('K3', 'Gafas Siroko', '129.95', 'EUR', 'many');
    }
}
