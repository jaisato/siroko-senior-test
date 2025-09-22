<?php

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Product;

use Doctrine\Persistence\ManagerRegistry;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures\ProductFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

class PostProductControllerTest extends WebTestCase
{
    /**
     * @return void
     */
    public function test_create_product(): void
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

        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);
        $url = $router->generate('api_create_product');

        $payload = [
            'name' => 'Test Product',
            'code' => 'TEST',
            'priceAmount' => '19.99',
            'priceCurrency' => 'EUR',
            'quantity' => 1
        ];

        $client->request('POST',
            $url,
            server: [
                'HTTP_ACCEPT'  => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $product = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseIsSuccessful();

        self::assertIsArray($product);

        self::assertArrayHasKey('id', $product);
        self::assertArrayHasKey('name', $product);
        self::assertSame($payload['name'], $product['name']);
        self::assertArrayHasKey('price', $product);
        self::assertArrayHasKey('code', $product);
        self::assertSame($payload['code'], $product['code']);
        self::assertArrayHasKey('quantity', $product);
        self::assertSame($payload['quantity'], $product['quantity']);
    }
}
