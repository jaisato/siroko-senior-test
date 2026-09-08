<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api\Controller\Product;

use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Siroko\Tests\Cart\Infrastructure\Api\ApiTestCase;

/**
 * PATCH /v1/products/{id}/stock: an absolute recount or a delta.
 */
final class PatchProductStockControllerTest extends ApiTestCase
{
    public function test_an_absolute_quantity_replaces_the_available_stock(): void
    {
        $product = $this->persistProduct(stock: 5);

        $this->request('PATCH', $this->url('api_adjust_product_stock', ['id' => $product->id()->toString()]), ['quantity' => 40]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(40, $this->json()['quantity']);
        self::assertSame(40, $this->stockOf($product));
    }

    public function test_a_positive_delta_adds_units(): void
    {
        $product = $this->persistProduct(stock: 5);

        $this->request('PATCH', $this->url('api_adjust_product_stock', ['id' => $product->id()->toString()]), ['delta' => 3]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(8, $this->json()['quantity']);
        self::assertSame(8, $this->stockOf($product));
    }

    public function test_a_negative_delta_removes_units(): void
    {
        $product = $this->persistProduct(stock: 5);

        $this->request('PATCH', $this->url('api_adjust_product_stock', ['id' => $product->id()->toString()]), ['delta' => -2]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, $this->json()['quantity']);
        self::assertSame(3, $this->stockOf($product));
    }

    /** Units reserved by carts are already off the count, so they can never be written off twice. */
    public function test_a_delta_cannot_take_the_available_stock_below_zero(): void
    {
        $product = $this->persistProduct(stock: 2);

        $this->request('PATCH', $this->url('api_adjust_product_stock', ['id' => $product->id()->toString()]), ['delta' => -3]);

        $this->assertProblem(409, 'below zero');
        self::assertSame(2, $this->stockOf($product));
    }

    /**
     * A positive delta is an administrative increment, not a refund, and it
     * leaves the same room a recount leaves.
     *
     * The units a pending or paid cart holds are on top of the available count
     * and come back to it when the cart is called off. Sent through the refund
     * path - which credits units that stop being held the moment they land, so
     * only the column's own ceiling applies - `{"delta":1}` on a product one
     * unit short of the maximum took the last slot the cart was going to need,
     * and the cancellation afterwards was refused with nowhere to put its unit
     * and rolled back in full.
     */
    public function test_a_positive_delta_leaves_room_for_the_units_a_cart_holds(): void
    {
        $product = $this->persistProduct(stock: Quantity::MAX_QUANTITY - 1);
        $cart = $this->persistCartWithLines(CartStatus::PENDING, [[$product, 1]]);

        $this->request('PATCH', $this->url('api_adjust_product_stock', ['id' => $product->id()->toString()]), ['delta' => 1]);

        $this->assertProblem(400, '1 unit(s)');
        self::assertSame(Quantity::MAX_QUANTITY - 1, $this->stockOf($product), 'the column is untouched');

        // And the cancellation that used to roll back still has somewhere to
        // put the unit it gives back.
        $this->request('DELETE', $this->url('api_cancel_cart_by_id', ['id' => $cart->id()->toString()]));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(Quantity::MAX_QUANTITY, $this->stockOf($product));
    }

    public function test_the_body_must_say_exactly_one_thing(): void
    {
        $product = $this->persistProduct(stock: 5);
        $url = $this->url('api_adjust_product_stock', ['id' => $product->id()->toString()]);

        $this->request('PATCH', $url, ['quantity' => 1, 'delta' => 1]);
        $this->assertProblem(400, 'exactly one');

        // The schema says `additionalProperties: false`: a name this endpoint
        // does not read is refused for being that, not for saying nothing.
        $this->request('PATCH', $url, ['units' => 1]);
        $this->assertProblem(400, 'does not accept');

        $this->request('PATCH', $url, '{}');
        $this->assertProblem(400, 'exactly one');

        $this->request('PATCH', $url, ['delta' => 0]);
        $this->assertProblem(400, 'changes nothing');

        $this->request('PATCH', $url, ['quantity' => -1]);
        $this->assertProblem(400, 'greater or equal to 0');

        $this->request('PATCH', $url, ['delta' => 'lots']);
        $this->assertProblem(400, '"delta" must be an integer');

        self::assertSame(5, $this->stockOf($product));
    }

    public function test_an_unknown_product_is_a_404_problem(): void
    {
        $this->request('PATCH', $this->url('api_adjust_product_stock', ['id' => Uuid::uuid4()->toString()]), ['quantity' => 1]);

        $this->assertProblem(404, 'Product');
    }

    public function test_a_withdrawn_product_is_a_404_problem(): void
    {
        $product = $this->persistProduct(stock: 5);
        $this->request('DELETE', $this->url('api_delete_product', ['id' => $product->id()->toString()]));

        $this->request('PATCH', $this->url('api_adjust_product_stock', ['id' => $product->id()->toString()]), ['quantity' => 1]);

        $this->assertProblem(404, 'Product');
        self::assertSame(5, $this->stockOf($product));
    }
}
