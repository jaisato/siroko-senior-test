<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Security;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Infrastructure\Api\OpenApi\OpenApiFactoryDecorator;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * The API with API_TOKENS set: every /v1 route wants a token, and carts and
 * orders belong to the customer behind it.
 *
 * The variable is set in the process environment before the kernel boots;
 * the container reads it at runtime, so the compiled test container serves
 * both modes. The other functional tests run with the variable empty, which
 * is what pins that the documented try-it-out flow needs no token.
 */
final class ApiTokenAuthenticationTest extends ApiTestCase
{
    private const TOKENS = 'alice-token:alice,bob-token:bob';

    protected function setUp(): void
    {
        self::tokens(self::TOKENS);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        self::tokens('');
    }

    public function test_the_versioned_api_requires_a_token(): void
    {
        $this->request('GET', $this->url('api_list_carts'));

        $this->assertProblem(401, 'Authentication required');
        self::assertSame('Bearer realm="api"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function test_an_unknown_token_is_a_401_problem(): void
    {
        $this->request('GET', $this->url('api_list_carts'), server: ['HTTP_AUTHORIZATION' => 'Bearer nope']);

        $this->assertProblem(401, 'not valid');
    }

    public function test_the_documentation_stays_public(): void
    {
        $this->request('GET', '/api/docs.jsonopenapi', server: ['HTTP_ACCEPT' => 'application/vnd.openapi+json']);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * With tokens on, the document stops offering the anonymous alternative.
     * `security` says which credential to send, and a client generated from a
     * protected deployment's document that believed it could send nothing
     * sent nothing and got 401 on every call. The two schemes stay: they say
     * how a token is presented, not whether one is needed.
     */
    public function test_the_documentation_no_longer_offers_anonymous_access(): void
    {
        $this->request('GET', '/api/docs.jsonopenapi', server: ['HTTP_ACCEPT' => 'application/vnd.openapi+json']);
        self::assertResponseStatusCodeSame(200);

        $document = $this->json();
        $security = $document['security'] ?? null;
        self::assertIsArray($security);

        self::assertNotContains([], $security, 'anonymous access is not an option while API_TOKENS is set');
        self::assertContains([OpenApiFactoryDecorator::BEARER_SCHEME => []], $security);
        self::assertContains([OpenApiFactoryDecorator::API_KEY_SCHEME => []], $security);
        self::assertArrayHasKey(OpenApiFactoryDecorator::BEARER_SCHEME, $document['components']['securitySchemes'] ?? []);
        self::assertArrayHasKey(OpenApiFactoryDecorator::API_KEY_SCHEME, $document['components']['securitySchemes'] ?? []);
    }

    public function test_a_cart_created_with_a_token_belongs_to_its_customer(): void
    {
        $product = $this->persistProduct();

        $this->request('POST', $this->url('api_create_cart'), ['products' => [['productId' => $product->id()->toString(), 'quantity' => 1]]], self::alice());

        self::assertResponseStatusCodeSame(201);
        $cart = $this->json();
        self::assertSame('alice', $cart['customerId']);

        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart['id']]), server: self::alice());
        self::assertResponseStatusCodeSame(200);

        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart['id']]), server: self::bob());
        $this->assertProblem(404, 'Cart');
    }

    /** Every cart write is scoped: another customer's cart does not exist. */
    public function test_another_customers_cart_cannot_be_changed_paid_or_canceled(): void
    {
        $product = $this->persistProduct(stock: 5);
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]], null, 'alice');
        $item = $cart->items()->first();
        self::assertNotFalse($item);
        $id = $cart->id()->toString();

        $this->request('PUT', $this->url('api_add_cart_product_by_id', ['cartId' => $id, 'productId' => $product->id()->toString()]), server: self::bob());
        $this->assertProblem(404, 'Cart');
        $this->request('PATCH', $this->url('api_change_cart_item_quantity', ['cartId' => $id, 'itemId' => $item->id()->toString()]), ['quantity' => 3], self::bob());
        $this->assertProblem(404, 'Cart');
        $this->request('DELETE', $this->url('api_delete_cart_item_by_id', ['cartId' => $id, 'itemId' => $item->id()->toString()]), server: self::bob());
        $this->assertProblem(404, 'Cart');
        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $id]), server: self::bob());
        $this->assertProblem(404, 'Cart');
        $this->request('DELETE', $this->url('api_cancel_cart_by_id', ['id' => $id]), server: self::bob());
        $this->assertProblem(404, 'Cart');

        self::assertSame(5, $this->stockOf($product), 'nothing moved');
        self::assertTrue($this->reloadCart($cart)->isPending());

        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $id]), server: self::alice());
        self::assertResponseStatusCodeSame(200, 'the owner may');
        self::assertSame('alice', $this->json()['order']['customerId']);
    }

    public function test_the_order_of_a_checkout_belongs_to_the_customer_too(): void
    {
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$this->persistProduct(), 1]], null, 'alice');
        $this->request('PUT', $this->url('api_cart_checkout_by_id', ['id' => $cart->id()->toString()]), server: self::alice());
        $orderId = $this->json()['order']['id'];

        $this->request('GET', $this->url('api_get_order_by_id', ['id' => $orderId]), server: self::alice());
        self::assertResponseStatusCodeSame(200);

        $this->request('GET', $this->url('api_get_order_by_id', ['id' => $orderId]), server: self::bob());
        $this->assertProblem(404, 'Order');
    }

    /**
     * Hers and the ownerless ones - the carts the rest of the API lets her
     * read and check out - and not bob's. Listing on ownership equality alone
     * left the ownerless ones out, so the one call a client makes to find out
     * which carts it may use disagreed with every call that uses them.
     */
    public function test_the_list_shows_the_carts_the_caller_may_use(): void
    {
        $product = $this->persistProduct();
        $alices = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]], null, 'alice');
        $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]], null, 'bob');
        $ownerless = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]]);

        $this->request('GET', $this->url('api_list_carts'), server: self::alice());

        $body = $this->json();
        $listed = array_column($body['carts'], 'id');
        sort($listed);
        $expected = [$alices->id()->toString(), $ownerless->id()->toString()];
        sort($expected);

        self::assertSame($expected, $listed);
        self::assertSame(2, $body['total']);
    }

    /** Carts that predate authentication belong to nobody and stay reachable by id. */
    public function test_an_ownerless_cart_is_open_to_any_authenticated_caller(): void
    {
        $cart = $this->persistCart(CartStatus::PENDING, $this->persistProduct());

        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart->id()->toString()]), server: self::bob());

        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['customerId']);
    }

    public function test_the_api_key_header_is_accepted_as_well(): void
    {
        $this->request('GET', $this->url('api_list_carts'), server: ['HTTP_X_API_KEY' => 'bob-token']);

        self::assertResponseStatusCodeSame(200);
    }

    public function test_products_need_a_token_as_well_but_are_not_scoped(): void
    {
        $product = $this->persistProduct();

        $this->request('GET', $this->url('api_get_product_by_id', ['id' => $product->id()->toString()]));
        $this->assertProblem(401);

        $this->request('GET', $this->url('api_get_product_by_id', ['id' => $product->id()->toString()]), server: self::bob());
        self::assertResponseStatusCodeSame(200);
    }

    public function test_an_unknown_id_and_somebody_elses_id_are_indistinguishable(): void
    {
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$this->persistProduct(), 1]], null, 'alice');

        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => $cart->id()->toString()]), server: self::bob());
        $theirs = $this->json();
        $this->request('GET', $this->url('api_get_cart_by_id', ['id' => Uuid::uuid4()->toString()]), server: self::bob());
        $unknown = $this->json();

        self::assertSame($unknown['status'], $theirs['status']);
        self::assertSame($unknown['title'], $theirs['title']);
    }

    /**
     * @return array<string, string>
     */
    private static function alice(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer alice-token'];
    }

    /**
     * @return array<string, string>
     */
    private static function bob(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer bob-token'];
    }

    private static function tokens(string $spec): void
    {
        $_ENV['API_TOKENS'] = $spec;
        $_SERVER['API_TOKENS'] = $spec;
        putenv('API_TOKENS=' . $spec);
    }
}
