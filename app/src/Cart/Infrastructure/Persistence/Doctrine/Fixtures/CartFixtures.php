<?php

declare(strict_types=1);

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
use Siroko\Cart\Domain\ValueObject\Quantity;

final class CartFixtures extends Fixture implements DependentFixtureInterface
{
    public const PRODUCT_INDEXES = [1, 3, 5];

    public function getDependencies(): array
    {
        return [ProductFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Faker::create('es_ES');

        $cart = new Cart(
            CartId::fromString($faker->uuid()),
            CartStatus::pending(),
        );

        foreach (self::PRODUCT_INDEXES as $i) {
            $product = $this->getReference(ProductFixtures::REF_PREFIX . $i, Product::class);

            $item = new CartItem(ItemId::fromString($faker->uuid()), $product);
            $cart->addItem($item);
            $manager->persist($item);

            // A line in a cart is a unit taken off the shelf. Seeding the cart
            // without touching the stock produced demo data the application
            // itself can never produce: units both reserved and available.
            $product->setQuantity(Quantity::decrement($product->quantity()));
        }

        $manager->persist($cart);
        $manager->flush();
    }
}
