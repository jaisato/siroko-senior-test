<?php

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Product;

use Doctrine\Persistence\ManagerRegistry;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures\ProductFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

class GetProductListControllerTest extends WebTestCase
{
    /**
     * @return void
     */
    public function test_get_product_list(): void
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
        $url = $router->generate('api_get_products', ['pageNumber' => 1, 'pageSize' => 10]);

        $client->request('GET', $url, [
            'headers' => ['accept' => 'application/json'],
        ]);

        $json = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseIsSuccessful();

        self::assertIsArray($json);
        self::assertArrayHasKey('products', $json);
        self::assertCount(10, $json['products']);

        foreach ($json['products'] as $product) {
            self::assertArrayHasKey('id', $product);
            self::assertArrayHasKey('name', $product);
            self::assertArrayHasKey('price', $product);
            self::assertArrayHasKey('code', $product);
            self::assertArrayHasKey('quantity', $product);
        }
    }
}
