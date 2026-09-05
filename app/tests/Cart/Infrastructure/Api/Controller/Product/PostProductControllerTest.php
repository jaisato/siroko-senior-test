<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Product;

use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

final class PostProductControllerTest extends ApiTestCase
{
    private const VALID = [
        'name' => 'Test Product',
        'code' => 'TEST',
        'priceAmount' => '19.99',
        'priceCurrency' => 'EUR',
        'quantity' => 1,
    ];

    public function test_create_product(): void
    {
        $this->request('POST', $this->url('api_create_product'), self::VALID);

        self::assertResponseStatusCodeSame(201);
        $product = $this->json();

        self::assertTrue(Uuid::isValid($product['id']));
        self::assertSame('Test Product', $product['name']);
        self::assertSame('TEST', $product['code']);
        self::assertSame("19,99\u{a0}€", $product['price']);
        self::assertSame(1, $product['quantity']);

        $this->request('GET', $this->url('api_get_product_by_id', ['id' => $product['id']]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($product, $this->json(), 'what was created is what is read back');
    }

    /** JSON clients that quote their numbers are still understood. */
    public function test_numeric_strings_are_accepted_where_numbers_are_expected(): void
    {
        $this->request('POST', $this->url('api_create_product'), [
            ...self::VALID,
            'code' => 1234,
            'quantity' => '7',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('1234', $this->json()['code']);
        self::assertSame(7, $this->json()['quantity']);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('malformedBodies')]
    public function test_a_malformed_body_is_a_400_problem(array $body, string $detail): void
    {
        $this->request('POST', $this->url('api_create_product'), $body);

        $this->assertProblem(400, $detail);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'missing field' => [array_diff_key(self::VALID, ['priceAmount' => 1]), '"priceAmount" is required'];
        yield 'code is an array' => [['code' => ['a']] + self::VALID, '"code" must be a string'];
        yield 'quantity is a decimal' => [['quantity' => 1.5] + self::VALID, '"quantity" must be an integer'];
        yield 'quantity is negative' => [['quantity' => -1] + self::VALID, 'greater or equal to 0'];
        yield 'name too short' => [['name' => 'ab'] + self::VALID, 'between 3 and 200'];
        yield 'code too long' => [['code' => str_repeat('X', 51)] + self::VALID, 'between 1 and 50'];
        yield 'unknown currency' => [['priceCurrency' => 'XYZ'] + self::VALID, 'currency'];
        yield 'amount not a number' => [['priceAmount' => 'nineteen'] + self::VALID, 'decimal number'];
        yield 'amount with too many decimals' => [['priceAmount' => '19.999'] + self::VALID, 'more decimals'];
        yield 'negative amount' => [['priceAmount' => '-1.00'] + self::VALID, 'negative'];
    }

    public function test_a_duplicate_code_is_a_409_problem(): void
    {
        $this->persistProduct(code: 'TEST');

        $this->request('POST', $this->url('api_create_product'), self::VALID);

        $this->assertProblem(409, 'already exists');
    }

    /** Errors raised before any controller runs use the same contract. */
    public function test_an_unsupported_method_is_a_405_problem(): void
    {
        $this->request('PATCH', $this->url('api_create_product'), self::VALID);

        $this->assertProblem(405);
    }

    public function test_an_unknown_route_is_a_404_problem(): void
    {
        $this->request('GET', '/api/v1/nothing-here');

        $this->assertProblem(404);
    }
}
