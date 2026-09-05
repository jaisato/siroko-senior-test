<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Resource;

use ApiPlatform\Metadata as API;
use ApiPlatform\OpenApi\Model;
use Siroko\Cart\Application\Dto\Cart\CartRead;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\AddCartProductController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\CheckoutCartController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\DeleteCartItemController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\GetCartController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\PostCartController;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Routing and OpenAPI metadata of the cart endpoints.
 *
 * This class is the only place the cart routes are declared. The controllers
 * used to carry `#[Route]` attributes for the same paths as well; both sets
 * were loaded, and the API Platform ones won every match, so the attributes
 * were dead copies that drifted (no names, no requirements) without anyone
 * noticing.
 *
 * Every id in a path must be a UUID. A value that is not one never reaches a
 * controller - the router answers 404, the same as for a well-formed id that
 * does not exist.
 */
#[API\ApiResource(
    shortName: 'Cart',
    operations: [
        new API\Get(
            name: 'api_get_cart_by_id',
            uriTemplate: '/v1/carts/{id}',
            requirements: ['id' => Requirement::UUID],
            controller: GetCartController::class,
            read: false,
            output: CartRead::class,
            openapi: new Model\Operation(
                summary: 'Get cart by id',
                parameters: [
                    new Model\Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'Cart UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
            ),
        ),
        new API\Post(
            name: 'api_create_cart',
            uriTemplate: '/v1/carts',
            controller: PostCartController::class,
            read: false,
            output: CartRead::class,
            openapi: new Model\Operation(
                summary: 'Create cart',
                requestBody: new Model\RequestBody(
                    description: 'JSON payload',
                    required: true,
                    content: new \ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['products'],
                                'additionalProperties' => false,
                                'properties' => [
                                    'products' => [
                                        'type' => 'array',
                                        'minItems' => 1,
                                        'maxItems' => 50,
                                        'items' => [
                                            'type' => 'object',
                                            'required' => ['productId', 'quantity'],
                                            'additionalProperties' => false,
                                            'properties' => [
                                                'productId' => ['type' => 'string', 'format' => 'uuid'],
                                                'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'examples' => [
                                'sample' => [
                                    'summary' => 'Carrito con 2 productos',
                                    'value' => [
                                        'products' => [
                                            ['productId' => '018f9f3b-8d18-7d73-9b86-9a4f2e6f5e9a', 'quantity' => 2],
                                            ['productId' => '0190aa11-bb22-4c33-8d44-ee5566778899', 'quantity' => 1],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
        ),
        new API\Delete(
            name: 'api_delete_cart_item_by_id',
            uriTemplate: '/v1/carts/{cartId}/items/{itemId}',
            requirements: ['cartId' => Requirement::UUID, 'itemId' => Requirement::UUID],
            controller: DeleteCartItemController::class,
            read: false,
            write: false,
            output: false,
            status: 204,
            openapi: new Model\Operation(
                summary: 'Delete cart item by id',
                parameters: [
                    new Model\Parameter(
                        name: 'cartId',
                        in: 'path',
                        required: true,
                        description: 'Cart UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                    new Model\Parameter(
                        name: 'itemId',
                        in: 'path',
                        required: true,
                        description: 'Item UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
            ),
        ),
        new API\Put(
            name: 'api_cart_checkout_by_id',
            uriTemplate: '/v1/carts/{id}/checkout',
            requirements: ['id' => Requirement::UUID],
            controller: CheckoutCartController::class,
            read: false,
            write: false,
            input: false,
            output: CartRead::class,
            openapi: new Model\Operation(
                summary: 'Checkout cart by id',
                parameters: [
                    new Model\Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'Cart UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
            ),
        ),
        new API\Put(
            name: 'api_add_cart_product_by_id',
            uriTemplate: '/v1/carts/{cartId}/products/{productId}/add',
            requirements: ['cartId' => Requirement::UUID, 'productId' => Requirement::UUID],
            controller: AddCartProductController::class,
            read: false,
            write: false,
            input: false,
            output: CartRead::class,
            openapi: new Model\Operation(
                summary: 'Add product to cart by id',
                parameters: [
                    new Model\Parameter(
                        name: 'cartId',
                        in: 'path',
                        required: true,
                        description: 'Cart UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                    new Model\Parameter(
                        name: 'productId',
                        in: 'path',
                        required: true,
                        description: 'Product UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
            ),
        ),
    ],
)]
final class CartResource {}
