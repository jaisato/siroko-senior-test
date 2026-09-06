<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\CreateCartCommand;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

final class PostCartControllerTest extends ApiTestCase
{
    public function test_create_cart(): void
    {
        $first = $this->persistProduct('First', stock: 5);
        $second = $this->persistProduct('Second', stock: 5);

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [
                ['productId' => $first->id()->toString(), 'quantity' => 2],
                ['productId' => $second->id()->toString(), 'quantity' => 1],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $cart = $this->json();

        self::assertTrue(Uuid::isValid($cart['id']));
        self::assertSame(CartStatus::PENDING, $cart['status']);
        self::assertCount(2, $cart['items'], 'one line per product, holding its units');

        $quantities = [];
        foreach ($cart['items'] as $item) {
            self::assertArrayHasKey('id', $item);
            self::assertArrayHasKey('name', $item);
            self::assertArrayHasKey('code', $item);
            self::assertArrayHasKey('price', $item);
            $quantities[$item['productId']] = $item['quantity'];
        }

        // Lines are created in product-id (lock) order, not request order.
        ksort($quantities);
        $expected = [$first->id()->toString() => 2, $second->id()->toString() => 1];
        ksort($expected);
        self::assertSame($expected, $quantities);
        self::assertSame(3, $this->stockOf($first), 'two units were reserved');
        self::assertSame(4, $this->stockOf($second));
    }

    public function test_lines_naming_the_same_product_become_one_line(): void
    {
        $product = $this->persistProduct(stock: 5);

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [
                ['productId' => $product->id()->toString(), 'quantity' => 2],
                ['productId' => $product->id()->toString(), 'quantity' => 1],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->json()['items']);
        self::assertSame(3, array_values($this->json()['items'])[0]['quantity']);
        self::assertSame(2, $this->stockOf($product));
    }

    public function test_products_in_two_currencies_are_a_409_problem_and_nothing_is_reserved(): void
    {
        $euros = $this->persistProduct('Euros', currency: 'EUR', stock: 5);
        $dollars = $this->persistProduct('Dollars', currency: 'USD', stock: 5);

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [
                ['productId' => $euros->id()->toString(), 'quantity' => 1],
                ['productId' => $dollars->id()->toString(), 'quantity' => 1],
            ],
        ]);

        $this->assertProblem(409, 'priced in EUR');
        self::assertSame(5, $this->stockOf($euros), 'the whole request rolled back');
        self::assertSame(5, $this->stockOf($dollars));
    }

    /** The deadline is what the expiry sweep acts on; CART_RESERVATION_TTL is 1800 s in the test env. */
    public function test_the_created_cart_reserves_its_units_for_the_configured_ttl(): void
    {
        $product = $this->persistProduct(stock: 5);

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [['productId' => $product->id()->toString(), 'quantity' => 1]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $cart = $this->json();
        $createdAt = new \DateTimeImmutable($cart['createdAt']);
        $expiresAt = new \DateTimeImmutable($cart['expiresAt']);
        self::assertEqualsWithDelta(time(), $createdAt->getTimestamp(), 5);
        self::assertSame(1800, $expiresAt->getTimestamp() - $createdAt->getTimestamp());
        self::assertStringEndsWith('+00:00', $cart['createdAt'], 'instants are reported in UTC');
    }

    public function test_the_created_cart_carries_its_totals(): void
    {
        $product = $this->persistProduct('Gafas', amount: '129.95', stock: 5);

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [['productId' => $product->id()->toString(), 'quantity' => 2]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $cart = $this->json();
        self::assertSame(2, $cart['itemCount']);
        self::assertSame('EUR', $cart['currency']);
        self::assertSame(['amount' => '259.90', 'currency' => 'EUR'], $cart['subtotal']);
        self::assertSame(['amount' => '259.90', 'currency' => 'EUR'], $cart['total']);
    }

    public function test_an_empty_body_is_a_400_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), '');

        $this->assertProblem(400, 'JSON body is required');
    }

    public function test_a_body_that_is_not_a_json_object_is_a_400_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), '[1, 2, 3]');

        $this->assertProblem(400, 'not a valid JSON object');
    }

    public function test_a_body_without_products_is_a_400_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), ['items' => []]);

        $this->assertProblem(400, '"products" is required');
    }

    /**
     * The operation declares `minItems: 1` and a 400, and only the upper bound
     * was checked: an empty list created an empty cart and answered 201, for a
     * cart the checkout then refuses as empty. A client generated from the
     * document sends what the document allows and gets an answer it does not
     * describe.
     */
    public function test_an_empty_product_list_is_a_400_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), ['products' => []]);

        $this->assertProblem(400, 'at least one line');
    }

    public function test_products_must_be_a_list(): void
    {
        $this->request('POST', $this->url('api_create_cart'), [
            'products' => ['productId' => Uuid::uuid4()->toString(), 'quantity' => 1],
        ]);

        $this->assertProblem(400, 'must be a list');
    }

    /**
     * Body identifiers are validated by the value object rather than the
     * router, so here a malformed UUID is a 400, not a 404.
     */
    public function test_a_malformed_product_id_is_a_400_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [['productId' => 'not-a-uuid', 'quantity' => 1]],
        ]);

        $this->assertProblem(400, 'UUID');
    }

    public function test_a_line_with_zero_units_is_a_400_problem(): void
    {
        $product = $this->persistProduct();

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [['productId' => $product->id()->toString(), 'quantity' => 0]],
        ]);

        $this->assertProblem(400, 'greater or equal to 1');
        self::assertSame(5, $this->stockOf($product), 'nothing was reserved');
    }

    public function test_a_non_integer_quantity_is_a_400_problem(): void
    {
        $product = $this->persistProduct();

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [['productId' => $product->id()->toString(), 'quantity' => 'two']],
        ]);

        $this->assertProblem(400, 'integer');
    }

    public function test_too_many_lines_is_a_400_problem(): void
    {
        $lines = [];
        for ($i = 0; $i <= CreateCartCommand::MAX_LINES; ++$i) {
            $lines[] = ['productId' => Uuid::uuid4()->toString(), 'quantity' => 1];
        }

        $this->request('POST', $this->url('api_create_cart'), ['products' => $lines]);

        $this->assertProblem(400, 'at most');
    }

    public function test_an_unknown_product_is_a_404_problem(): void
    {
        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [['productId' => Uuid::uuid4()->toString(), 'quantity' => 1]],
        ]);

        $this->assertProblem(404, 'Product');
    }

    public function test_a_product_without_enough_stock_is_a_409_problem_and_nothing_is_reserved(): void
    {
        $available = $this->persistProduct('Available', stock: 5);
        $scarce = $this->persistProduct('Scarce', stock: 1);

        $this->request('POST', $this->url('api_create_cart'), [
            'products' => [
                ['productId' => $available->id()->toString(), 'quantity' => 1],
                ['productId' => $scarce->id()->toString(), 'quantity' => 2],
            ],
        ]);

        $this->assertProblem(409, 'units available');
        self::assertSame(5, $this->stockOf($available), 'the whole request rolled back');
        self::assertSame(1, $this->stockOf($scarce));
    }
}
