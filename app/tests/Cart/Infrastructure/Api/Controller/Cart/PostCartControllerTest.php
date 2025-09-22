<?php

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Doctrine\Persistence\ManagerRegistry;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures\ProductFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

class PostCartControllerTest extends WebTestCase
{
    public function test_create_cart(): void
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
        $products = $productRepository->findAll(1, 10);

        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);
        $url = $router->generate('api_create_cart');

        $payload = [
            'products' => [
                [
                    'productId' => $products[0]->id()->toString(),
                    'quantity' => 1,
                ],
                [
                    'productId' => $products[1]->id()->toString(),
                    'quantity' => 1,
                ],
            ]
        ];

        $client->request('POST',
            $url,
            server: [
                'HTTP_ACCEPT'  => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $cart = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseIsSuccessful();

        self::assertIsArray($cart);

        self::assertArrayHasKey('id', $cart);
        self::assertArrayHasKey('status', $cart);
        self::assertSame(1, $cart['status']);
        self::assertArrayHasKey('items', $cart);
        self::assertIsArray($cart['items']);
        self::assertCount(2, $cart['items']);

        foreach ($cart['items'] as $item) {
            self::assertArrayHasKey('id', $item);
            self::assertArrayHasKey('name', $item);
            self::assertArrayHasKey('code', $item);
            self::assertArrayHasKey('price', $item);
        }
    }
}
