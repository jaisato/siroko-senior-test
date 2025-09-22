<?php

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Product;

use Doctrine\Persistence\ManagerRegistry;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures\ProductFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

class GetProductByIdControllerTest extends WebTestCase
{
    /**
     * @return void
     */
    public function test_get_product_by_id(): void
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
        $products = $productRepository->findAll(1, 1);

        $productId = $products[0]->id()->toString();

        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);
        $url = $router->generate('api_get_product_by_id', ['id' => $productId]);

        $client->request('GET', $url, [
            'headers' => ['accept' => 'application/json'],
        ]);

        $product = json_decode(
            $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertResponseStatusCodeSame(200);
        self::assertResponseIsSuccessful();

        self::assertArrayHasKey('id', $product);
        self::assertArrayHasKey('name', $product);
        self::assertArrayHasKey('price', $product);
        self::assertArrayHasKey('code', $product);
        self::assertArrayHasKey('quantity', $product);
    }
}
