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

    public function test_q_matches_name_or_code_regardless_of_case(): void
    {
        $this->persistProduct('Gafas de sol', 'K3-BLACK');
        $this->persistProduct('Casco', 'H1');
        $this->persistProduct('Funda de gafas', 'F1');

        $this->request('GET', $this->url('api_get_products', ['q' => 'GAFAS']));
        self::assertSame(['Funda de gafas', 'Gafas de sol'], array_column($this->json()['products'], 'name'));

        $this->request('GET', $this->url('api_get_products', ['q' => 'k3-bl']));
        self::assertSame(['Gafas de sol'], array_column($this->json()['products'], 'name'));
        self::assertSame(1, $this->json()['total'], 'the total counts the matches, not the catalogue');
    }

    /** `%` and `_` in the text mean themselves, not "anything". */
    public function test_q_wildcards_are_literal(): void
    {
        $this->persistProduct('100% cotton', 'C1');
        $this->persistProduct('100 cotton', 'C2');
        $this->persistProduct('a_b', 'U1');
        $this->persistProduct('axb', 'U2');

        $this->request('GET', $this->url('api_get_products', ['q' => '100%']));
        self::assertSame(['100% cotton'], array_column($this->json()['products'], 'name'));

        $this->request('GET', $this->url('api_get_products', ['q' => 'a_b']));
        self::assertSame(['a_b'], array_column($this->json()['products'], 'name'));
    }

    public function test_prices_can_be_bounded_and_sorted(): void
    {
        $this->persistProduct('Cheap', amount: '5.00');
        $this->persistProduct('Mid', amount: '20.00');
        $this->persistProduct('Dear', amount: '99.99');

        $this->request('GET', $this->url('api_get_products', ['minPrice' => '5', 'maxPrice' => '20.00']));
        self::assertSame(['Cheap', 'Mid'], array_column($this->json()['products'], 'name'), 'bounds are inclusive');

        $this->request('GET', $this->url('api_get_products', ['sort' => '-price']));
        self::assertSame(['Dear', 'Mid', 'Cheap'], array_column($this->json()['products'], 'name'));

        $this->request('GET', $this->url('api_get_products', ['sort' => 'price', 'minPrice' => '10']));
        self::assertSame(['Mid', 'Dear'], array_column($this->json()['products'], 'name'));
    }

    public function test_in_stock_filters_on_available_units(): void
    {
        $this->persistProduct('Available', stock: 3);
        $this->persistProduct('Sold out', stock: 0);

        $this->request('GET', $this->url('api_get_products', ['inStock' => 'true']));
        self::assertSame(['Available'], array_column($this->json()['products'], 'name'));

        $this->request('GET', $this->url('api_get_products', ['inStock' => 'false']));
        self::assertSame(['Sold out'], array_column($this->json()['products'], 'name'));

        $this->request('GET', $this->url('api_get_products', ['inStock' => '1']));
        self::assertSame(['Available'], array_column($this->json()['products'], 'name'));
    }

    public function test_sorting_by_code_and_name_descending(): void
    {
        $this->persistProduct('Bravo', 'B');
        $this->persistProduct('Alpha', 'C');
        $this->persistProduct('Charlie', 'A');

        $this->request('GET', $this->url('api_get_products', ['sort' => 'code']));
        self::assertSame(['Charlie', 'Bravo', 'Alpha'], array_column($this->json()['products'], 'name'));

        $this->request('GET', $this->url('api_get_products', ['sort' => '-name']));
        self::assertSame(['Charlie', 'Bravo', 'Alpha'], array_column($this->json()['products'], 'name'));

        $this->request('GET', $this->url('api_get_products', ['sort' => '-code']));
        self::assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($this->json()['products'], 'name'));
    }

    public function test_withdrawn_products_are_not_listed(): void
    {
        $this->persistProduct('Kept');
        $gone = $this->persistProduct('Gone');
        $this->request('DELETE', $this->url('api_delete_product', ['id' => $gone->id()->toString()]));

        $this->request('GET', $this->url('api_get_products'));

        self::assertSame(['Kept'], array_column($this->json()['products'], 'name'));
        self::assertSame(1, $this->json()['total']);
    }

    public function test_unusable_filters_are_400_problems(): void
    {
        $this->request('GET', $this->url('api_get_products', ['sort' => 'popularity']));
        $this->assertProblem(400, '"sort" must be one of');

        $this->request('GET', $this->url('api_get_products', ['minPrice' => 'cheap']));
        $this->assertProblem(400, '"minPrice"');

        $this->request('GET', $this->url('api_get_products', ['minPrice' => '20', 'maxPrice' => '10']));
        $this->assertProblem(400, 'cannot be greater');

        $this->request('GET', $this->url('api_get_products', ['inStock' => 'maybe']));
        $this->assertProblem(400, '"inStock" must be true or false');

        $this->request('GET', $this->url('api_get_products', ['q' => str_repeat('x', 101)]));
        $this->assertProblem(400, 'at most 100');
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
