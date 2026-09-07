<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * GET /v1/carts with authentication off: every cart, newest first.
 * Scoping to the caller is covered by ApiTokenAuthenticationTest.
 */
final class ListCartsControllerTest extends ApiTestCase
{
    public function test_it_lists_every_cart_newest_first_with_pagination_metadata(): void
    {
        $product = $this->persistProduct();
        $older = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]]);
        $paid = $this->persistCartWithLines(CartStatus::PAID, [[$product, 2]], null, 'alice');
        $this->em()->createQueryBuilder()->update(\Siroko\Cart\Domain\Entity\Cart::class, 'c')->set('c.createdAt', ':old')->where('c.id = :id')
            ->setParameter('old', new \DateTimeImmutable('-1 day'), 'datetime_immutable')->setParameter('id', $older->id(), 'cart_id')->getQuery()->execute();

        $this->request('GET', $this->url('api_list_carts'));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame([$paid->id()->toString(), $older->id()->toString()], array_column($body['carts'], 'id'));
        self::assertSame('alice', $body['carts'][0]['customerId']);
        self::assertNull($body['carts'][1]['customerId']);
        self::assertSame(2, $body['carts'][0]['itemCount']);
        self::assertSame(1, $body['page']);
        self::assertSame(20, $body['pageSize']);
        self::assertSame(2, $body['total']);
        self::assertSame(1, $body['pages']);
    }

    public function test_it_filters_by_status_and_pages(): void
    {
        $product = $this->persistProduct();
        $this->persistCart(CartStatus::PENDING, $product);
        $this->persistCart(CartStatus::PENDING, $product);
        $this->persistCart(CartStatus::PAID, $product);

        $this->request('GET', $this->url('api_list_carts', ['status' => CartStatus::PAID]));
        self::assertSame(1, $this->json()['total']);
        self::assertSame(CartStatus::PAID, $this->json()['carts'][0]['status']);

        $this->request('GET', $this->url('api_list_carts', ['status' => CartStatus::PENDING, 'pageSize' => 1, 'pageNumber' => 2]));
        $body = $this->json();
        self::assertCount(1, $body['carts']);
        self::assertSame(2, $body['total']);
        self::assertSame(2, $body['pages']);
        self::assertSame(2, $body['page']);
    }

    public function test_an_empty_list_is_an_empty_array(): void
    {
        $this->request('GET', $this->url('api_list_carts'));

        self::assertSame(['carts' => [], 'page' => 1, 'pageSize' => 20, 'total' => 0, 'pages' => 0], $this->json());
    }

    public function test_an_unknown_status_is_a_400_problem(): void
    {
        $this->request('GET', $this->url('api_list_carts', ['status' => 9]));
        $this->assertProblem(400, '"status" must be');

        $this->request('GET', $this->url('api_list_carts', ['status' => 'paid']));
        $this->assertProblem(400, '"status" must be an integer');
    }

    /**
     * 0 was the sentinel for "no filter given", so sending it read as sending
     * nothing: a value the operation documents as a 400 came back with every
     * cart instead. Of all the answers to a malformed filter, the widest.
     */
    public function test_an_explicit_zero_status_is_a_400_and_not_the_whole_catalogue(): void
    {
        $this->request('GET', $this->url('api_list_carts', ['status' => 0]));
        $this->assertProblem(400, '"status" must be');

        $this->request('GET', $this->url('api_list_carts', ['status' => -1]));
        $this->assertProblem(400, '"status" must be');
    }

    /** Absent is still absent, and an empty value is still absent. */
    public function test_no_status_filters_nothing(): void
    {
        $this->request('GET', $this->url('api_list_carts'));
        $withoutIt = $this->json();

        $this->request('GET', $this->url('api_list_carts', ['status' => '']));

        self::assertSame($withoutIt, $this->json());
    }
}
