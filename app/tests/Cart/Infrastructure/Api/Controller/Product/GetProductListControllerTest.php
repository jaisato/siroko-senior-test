<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Query\Product\GetProductListQuery;
use Siroko\Cart\Infrastructure\Api\Controller\Product\GetProductListController;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Fixtures\ProductFixtures;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

final class GetProductListControllerTest extends ApiTestCase
{
    public function test_get_product_list(): void
    {
        $this->loadFixtures([ProductFixtures::class]);

        $this->request('GET', $this->url('api_get_products', ['pageNumber' => 1, 'pageSize' => 10]));

        self::assertResponseStatusCodeSame(200);
        $json = $this->json();

        self::assertCount(10, $json['products']);
        self::assertSame(1, $json['page']);
        self::assertSame(10, $json['pageSize']);
        self::assertSame(ProductFixtures::COUNT, $json['total']);
        self::assertSame(2, $json['pages']);

        foreach ($json['products'] as $product) {
            self::assertArrayHasKey('id', $product);
            self::assertArrayHasKey('name', $product);
            self::assertArrayHasKey('price', $product);
            self::assertArrayHasKey('code', $product);
            self::assertArrayHasKey('quantity', $product);
        }
    }

    /**
     * `public array $products;` was never initialised, so a page with no rows
     * serialised as `{}` instead of an empty list.
     */
    public function test_an_empty_catalogue_is_an_empty_list(): void
    {
        $this->request('GET', $this->url('api_get_products'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['products' => [], 'page' => 1, 'pageSize' => GetProductListController::DEFAULT_PAGE_SIZE, 'total' => 0, 'pages' => 0],
            $this->json(),
        );
    }

    public function test_the_default_page_holds_twenty_products(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $this->persistProduct(\sprintf('Product %02d', $i));
        }

        $this->request('GET', $this->url('api_get_products'));

        $json = $this->json();
        self::assertCount(20, $json['products']);
        self::assertSame(25, $json['total']);
        self::assertSame(2, $json['pages']);
    }

    public function test_pages_are_ordered_by_name_and_do_not_overlap(): void
    {
        foreach (['Cherry', 'Apple', 'Banana', 'Date', 'Elderberry'] as $name) {
            $this->persistProduct($name);
        }

        $this->request('GET', $this->url('api_get_products', ['pageNumber' => 1, 'pageSize' => 2]));
        $first = array_column($this->json()['products'], 'name');

        $this->request('GET', $this->url('api_get_products', ['pageNumber' => 2, 'pageSize' => 2]));
        $second = array_column($this->json()['products'], 'name');

        $this->request('GET', $this->url('api_get_products', ['pageNumber' => 3, 'pageSize' => 2]));
        $third = $this->json();

        self::assertSame(['Apple', 'Banana'], $first);
        self::assertSame(['Cherry', 'Date'], $second);
        self::assertSame(['Elderberry'], array_column($third['products'], 'name'));
        self::assertSame(3, $third['pages']);
    }

    public function test_a_page_past_the_end_is_empty_but_still_describes_the_catalogue(): void
    {
        $this->persistProduct();

        $this->request('GET', $this->url('api_get_products', ['pageNumber' => 9, 'pageSize' => 5]));

        $json = $this->json();
        self::assertSame([], $json['products']);
        self::assertSame(9, $json['page']);
        self::assertSame(1, $json['total']);
        self::assertSame(1, $json['pages']);
    }

    /** `?pageSize=1000000` used to pull the whole table into one response. */
    public function test_integer_bounds_are_clamped(): void
    {
        $this->request('GET', $this->url('api_get_products', ['pageNumber' => 0, 'pageSize' => 1000000]));

        $json = $this->json();
        self::assertSame(1, $json['page']);
        self::assertSame(GetProductListQuery::MAX_PAGE_SIZE, $json['pageSize']);

        $this->request('GET', $this->url('api_get_products', ['pageNumber' => -2, 'pageSize' => -4]));

        $json = $this->json();
        self::assertSame(1, $json['page']);
        self::assertSame(1, $json['pageSize']);
    }

    /** `InputBag::getInt()` threw on this, which surfaced as a 500. */
    public function test_a_query_value_that_is_not_an_integer_is_a_400_problem(): void
    {
        $this->request('GET', $this->url('api_get_products', ['pageNumber' => 'abc']));

        $this->assertProblem(400, '"pageNumber" must be an integer');

        $this->request('GET', $this->url('api_get_products', ['pageSize' => '1.5']));

        $this->assertProblem(400, '"pageSize" must be an integer');
    }
}
