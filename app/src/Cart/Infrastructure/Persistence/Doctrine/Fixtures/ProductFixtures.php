<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory as Faker;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class ProductFixtures extends Fixture
{
    public const REF_PREFIX = 'product:';

    public const COUNT = 20;

    public const MIN_STOCK = 1;

    public const MAX_STOCK = 100;

    public function load(ObjectManager $manager): void
    {
        $faker = Faker::create('es_ES');

        for ($i = 0; $i < self::COUNT; ++$i) {
            $id = ProductId::fromString($faker->uuid());
            /** @var string $words */
            $words = $faker->unique()->words(3, true);
            $name = Name::fromString($words);
            $code = ProductCode::fromString(strtoupper($faker->unique()->bothify('SKU-####-??')));
            $price = Price::of(number_format($faker->randomFloat(2, 5, 200), 2, '.', ''), 'EUR');
            // `randomNumber(1, 100)` read as (nbMaxDigits: 1, strict: 100), so
            // every product shipped with 1-9 units - not the 1-100 intended.
            $quantity = new Quantity($faker->numberBetween(self::MIN_STOCK, self::MAX_STOCK));
            $product = new Product($id, $code, $name, $price, $quantity);

            $manager->persist($product);

            $this->addReference(self::REF_PREFIX . $i, $product);
        }

        $manager->flush();
    }
}
