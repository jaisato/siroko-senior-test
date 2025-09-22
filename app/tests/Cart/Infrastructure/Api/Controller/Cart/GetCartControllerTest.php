<?php

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Doctrine\Persistence\ManagerRegistry;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures\ProductFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

class GetCartControllerTest extends WebTestCase
{
    public function test_get_cart_by_id(): void
    {
        $client = static::createClient();

        $registry = static::getContainer()->get(ManagerRegistry::class);
        $emName = array_keys($registry->getManagerNames())[0];

        $tools = static::getContainer()->get(DatabaseToolCollection::class)->get($emName);

        $conn = static::getContainer()->get('doctrine')->getConnection();
        if ('' === (string) $conn->getDatabase()) {
            $conn->executeStatement('USE `siroko_cart_test`');
        }

        $tools->loadFixtures([
            ProductFixtures::class,
        ], true);

        /** @var ProductRepository $productRepository */
        $productRepository = static::getContainer()->get(ProductRepository::class);

        /** @var array|Product[] $products */
        $products = $productRepository->findAll(1, 5);

        /** @var CartRepository $cartRepository */
        $cartRepository = static::getContainer()->get(CartRepository::class);

        $cart = new Cart(
            CartId::fromString(Uuid::uuid4()->toString()),
            new CartStatus(CartStatus::PENDING),
        );

        foreach ($products as $product) {
            $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
            $cart->addItem($item);
        }

        $cartRepository->save($cart);

        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);
        $url = $router->generate('api_get_cart_by_id', ['id' => $cart->id()->toString()]);

        $client->request('GET', $url, [
            'headers' => ['accept' => 'application/json'],
        ]);

        $cart = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertResponseStatusCodeSame(200);
        self::assertResponseIsSuccessful();

        self::assertIsArray($cart);

        self::assertArrayHasKey('id', $cart);
        self::assertArrayHasKey('status', $cart);
        self::assertSame(1, $cart['status']);
        self::assertArrayHasKey('items', $cart);
        self::assertIsArray($cart['items']);
        self::assertCount(5, $cart['items']);

        foreach ($cart['items'] as $item) {
            self::assertArrayHasKey('id', $item);
            self::assertArrayHasKey('name', $item);
            self::assertArrayHasKey('code', $item);
            self::assertArrayHasKey('price', $item);
        }
    }
}
