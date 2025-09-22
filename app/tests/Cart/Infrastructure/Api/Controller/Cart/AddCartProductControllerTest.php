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

class AddCartProductControllerTest extends WebTestCase
{
    public function test_add_cart_product_by_id(): void
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

        $addProduct = null;
        foreach ($products as $product) {
            if ($addProduct === null) {
                if ($product->quantity()->asInt() > 1) {
                    $addProduct = $product;
                }
            }
            $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
            $cart->addItem($item);
        }

        $cartRepository->save($cart);

        self::assertCount(5, $cart->items()->toArray());

        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);
        $url = $router->generate(
            'api_add_cart_product_by_id',
            ['cartId' => $cart->id()->toString(), 'productId' => $addProduct->id()->toString()]
        );

        $client->request('PUT', $url, [
            'headers' => ['accept' => 'application/json'],
        ]);

        $cartResponse = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertResponseStatusCodeSame(200);
        self::assertResponseIsSuccessful();

        self::assertIsArray($cartResponse);

        self::assertArrayHasKey('id', $cartResponse);
        self::assertSame($cart->id()->toString(), $cartResponse['id']);
        self::assertArrayHasKey('items', $cartResponse);
        self::assertCount(6, $cartResponse['items']);
    }
}
