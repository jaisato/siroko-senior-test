<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory as Faker;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class ProductFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $faker = Faker::create('es_ES');

        for ($i = 0; $i < 20; $i++) {
            $id   = ProductId::fromString($faker->uuid);
            $name = new Name($faker->unique()->words(3, true));
            $code = new ProductCode(strtoupper($faker->unique()->bothify('SKU-####-??')));
            $price = Price::of((string)$faker->randomFloat(2, 5, 200), 'EUR');
            $quantity = new Quantity($faker->randomNumber(1, 100));
            $product = new Product($id, $code, $name, $price, $quantity);

            $manager->persist($product);
        }

        $manager->flush();
    }
}
