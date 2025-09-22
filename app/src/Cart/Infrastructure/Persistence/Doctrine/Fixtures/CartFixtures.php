<?php

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory as Faker;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;

final class CartFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [ProductFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Faker::create('es_ES');

        $cart = new Cart(
            CartId::fromString($faker->uuid),
            new CartStatus(CartStatus::PENDING),
        );

        $productIndexes = [1, 3, 5];

        foreach ($productIndexes as $i) {
            /** @var \Siroko\Cart\Domain\Entity\Product $product */
            $product = $this->getReference(ProductFixtures::REF_PREFIX . $i, Product::class);

            $item = new CartItem(ItemId::fromString($faker->uuid()), $product);
            $cart->addItem($item);
            $manager->persist($item);
        }

        $manager->persist($cart);
        $manager->flush();
    }
}
