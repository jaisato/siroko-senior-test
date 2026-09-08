<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures\CartFixtures;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures\ProductFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FixturesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;
    }

    /**
     * `randomNumber(1, 100)` meant (nbMaxDigits: 1, strict), so every demo
     * product had between 1 and 9 units.
     */
    public function test_product_fixtures_seed_twenty_products_with_realistic_stock(): void
    {
        $this->load([ProductFixtures::class]);

        $products = $this->em->getRepository(Product::class)->findAll();

        self::assertCount(ProductFixtures::COUNT, $products);

        $codes = [];
        foreach ($products as $product) {
            self::assertGreaterThanOrEqual(ProductFixtures::MIN_STOCK, $product->quantity()->asInt());
            self::assertLessThanOrEqual(ProductFixtures::MAX_STOCK, $product->quantity()->asInt());
            self::assertSame('EUR', $product->price()->currency()->getCurrencyCode());
            $codes[] = $product->code()->toString();
        }

        self::assertSame($codes, array_unique($codes), 'codes are unique');
    }

    /** The demo cart's lines are units taken off the shelf, as the application would do. */
    public function test_cart_fixtures_reserve_the_stock_of_the_products_they_put_in_the_cart(): void
    {
        $this->load([ProductFixtures::class, CartFixtures::class]);

        $carts = $this->em->getRepository(Cart::class)->findAll();
        self::assertCount(1, $carts);
        $cart = $carts[0];

        self::assertTrue($cart->isPending());
        self::assertCount(\count(CartFixtures::PRODUCT_INDEXES), $cart->items());

        $this->em->clear();

        foreach ($this->em->getRepository(CartItem::class)->findAll() as $item) {
            $product = $item->getProduct();
            // Every product was seeded with at least MIN_STOCK units and lost
            // exactly one to the cart, so none can be at the maximum any more.
            self::assertLessThan(ProductFixtures::MAX_STOCK, $product->quantity()->asInt());
            self::assertGreaterThanOrEqual(ProductFixtures::MIN_STOCK - 1, $product->quantity()->asInt());
        }
    }

    /**
     * @param list<class-string> $classes
     */
    private function load(array $classes): void
    {
        $tools = static::getContainer()->get(DatabaseToolCollection::class);

        $tools->get()->loadFixtures($classes, true);
    }
}
