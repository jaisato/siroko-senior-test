<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Resource;

use ApiPlatform\Metadata as API;
use ApiPlatform\OpenApi\Model;
use Siroko\Cart\Application\Dto\Cart\CartRead;
use Siroko\Cart\Application\Dto\Cart\CartReadCollection;
use Siroko\Cart\Application\Dto\Cart\CheckoutRead;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\AddCartProductController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\CancelCartController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\ChangeCartItemQuantityController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\CheckoutCartController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\DeleteCartItemController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\DeliverCartController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\GetCartController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\ListCartsController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\PostCartController;
use Siroko\Cart\Infrastructure\Api\OpenApi\Problem;
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
 *
 * Every operation lists the error responses it can answer, each an RFC 7807
 * problem (`Problem` schema). `errors: []` turns off the generic 400/422/404
 * stubs API Platform would otherwise add, which described its own error
 * format - not the one ApiExceptionMapper sends - and promised a 422 on
 * writes that never answer one. The 401 that authentication adds to every
 * versioned route is put in by OpenApiFactoryDecorator, not repeated here.
 */
#[API\ApiResource(
    shortName: 'Cart',
    description: 'Shopping carts: open one with its lines, change them, pay (which places an order), deliver or cancel. Units are reserved from stock while a cart is pending and go back when it is canceled or its reservation expires.',
    operations: [
        new API\Get(
            name: 'api_get_cart_by_id',
            uriTemplate: '/v1/carts/{id}',
            requirements: ['id' => Requirement::UUID],
            controller: GetCartController::class,
            read: false,
            output: CartRead::class,
            errors: [],
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
                responses: [
                    404 => new Model\Response('No cart has this id, or it belongs to another customer.', new \ArrayObject(Problem::CONTENT)),
                ],
            ),
        ),
        new API\GetCollection(
            name: 'api_list_carts',
            uriTemplate: '/v1/carts',
            controller: ListCartsController::class,
            read: false,
            output: CartReadCollection::class,
            paginationEnabled: false,
            errors: [],
            openapi: new Model\Operation(
                summary: 'List carts',
                description: 'One page of carts, newest first, with pagination metadata (page, pageSize, total, pages). With authentication on (API_TOKENS set) only the caller\'s carts are listed; without it, every cart.',
                parameters: [
                    new Model\Parameter(name: 'pageNumber', in: 'query', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
                    new Model\Parameter(name: 'pageSize', in: 'query', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]),
                    new Model\Parameter(name: 'status', in: 'query', description: '1 pending, 2 paid, 3 delivered, 4 canceled', schema: ['type' => 'integer', 'enum' => [1, 2, 3, 4]]),
                ],
                responses: [
                    400 => new Model\Response('pageNumber, pageSize or status is not an integer, or status is not one of 1 to 4.', new \ArrayObject(Problem::CONTENT)),
                ],
            ),
        ),
        new API\Post(
            name: 'api_create_cart',
            uriTemplate: '/v1/carts',
            controller: PostCartController::class,
            read: false,
            output: CartRead::class,
            errors: [],
            openapi: new Model\Operation(
                summary: 'Create cart',
                parameters: [
                    new Model\Parameter(
                        name: 'Idempotency-Key',
                        in: 'header',
                        required: false,
                        description: 'Optional. A retry with the same key and body replays the original response (marked Idempotent-Replayed: true) instead of executing again; the same key with a different request answers 422. Keys expire after IDEMPOTENCY_TTL seconds.',
                        schema: ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    ),
                ],
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
                                    'summary' => 'A cart with two products',
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
                responses: [
                    400 => new Model\Response('The body is not a JSON object, `products` is missing or not a list of 1 to 50 {productId, quantity} objects, the body or an entry names a field this endpoint does not read, a productId is not a UUID, a quantity is not an integer between 1 and 100, the lines of one product add up to more than 100 units, or ' . Problem::MALFORMED_IDEMPOTENCY_KEY . '.', new \ArrayObject(Problem::CONTENT)),
                    404 => new Model\Response('A product of the request does not exist or has been withdrawn.', new \ArrayObject(Problem::CONTENT)),
                    409 => new Model\Response('A product has fewer units available than requested, or is priced in another currency than the rest of the cart; or ' . Problem::IDEMPOTENCY_KEY_HELD . '.', new \ArrayObject(Problem::CONTENT)),
                    422 => new Model\Response(Problem::IDEMPOTENCY_KEY_REUSED, new \ArrayObject(Problem::CONTENT)),
                ],
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
            errors: [],
            openapi: new Model\Operation(
                summary: 'Delete cart item by id',
                description: 'Removes the line and returns every unit it held to stock.',
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
                responses: [
                    404 => new Model\Response('No cart has this id (or it belongs to another customer), or the line is not in this cart.', new \ArrayObject(Problem::CONTENT)),
                    409 => new Model\Response('The cart is not pending: the lines of a paid cart are what was bought.', new \ArrayObject(Problem::CONTENT)),
                ],
            ),
        ),
        new API\Patch(
            name: 'api_change_cart_item_quantity',
            uriTemplate: '/v1/carts/{cartId}/items/{itemId}',
            requirements: ['cartId' => Requirement::UUID, 'itemId' => Requirement::UUID],
            controller: ChangeCartItemQuantityController::class,
            read: false,
            write: false,
            deserialize: false,
            input: false,
            output: CartRead::class,
            errors: [],
            openapi: new Model\Operation(
                summary: 'Set the quantity of a cart line',
                description: 'Sets the line to exactly `quantity` units, reserving or returning the difference in stock. A quantity of 0 removes the line. Answers the whole cart.',
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
                requestBody: new Model\RequestBody(
                    description: 'JSON payload',
                    required: true,
                    content: new \ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['quantity'],
                                'additionalProperties' => false,
                                'properties' => [
                                    'quantity' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => '0 removes the line'],
                                ],
                            ],
                        ],
                    ]),
                ),
                responses: [
                    400 => new Model\Response('The body is not a JSON object, names a field this endpoint does not read, or `quantity` is missing or not an integer between 0 and 100.', new \ArrayObject(Problem::CONTENT)),
                    404 => new Model\Response('No cart has this id (or it belongs to another customer), or the line is not in this cart.', new \ArrayObject(Problem::CONTENT)),
                    409 => new Model\Response('The cart is not pending, or the extra units are not available.', new \ArrayObject(Problem::CONTENT)),
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
            output: CheckoutRead::class,
            errors: [],
            openapi: new Model\Operation(
                summary: 'Checkout cart by id',
                description: 'Pays a pending, non-empty cart and places an order for it. Answers the paid cart together with the order (`order.id` is what to keep). The cart becomes read-only; `PUT .../deliver` and `DELETE` are the only writes left.',
                parameters: [
                    new Model\Parameter(
                        name: 'Idempotency-Key',
                        in: 'header',
                        required: false,
                        description: 'Optional. A retry with the same key and body replays the original response (marked Idempotent-Replayed: true) instead of executing again; the same key with a different request answers 422. Keys expire after IDEMPOTENCY_TTL seconds.',
                        schema: ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    ),
                    new Model\Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'Cart UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
                responses: [
                    400 => new Model\Response('The Idempotency-Key header is not 1 to 255 printable characters without whitespace.', new \ArrayObject(Problem::CONTENT)),
                    404 => new Model\Response('No cart has this id, or it belongs to another customer.', new \ArrayObject(Problem::CONTENT)),
                    409 => new Model\Response('The cart is not pending (already paid, delivered or canceled), or it is empty; or ' . Problem::IDEMPOTENCY_KEY_HELD . '.', new \ArrayObject(Problem::CONTENT)),
                    422 => new Model\Response(Problem::IDEMPOTENCY_KEY_REUSED, new \ArrayObject(Problem::CONTENT)),
                ],
            ),
        ),
        new API\Put(
            name: 'api_cart_deliver_by_id',
            uriTemplate: '/v1/carts/{id}/deliver',
            requirements: ['id' => Requirement::UUID],
            controller: DeliverCartController::class,
            read: false,
            write: false,
            input: false,
            output: CartRead::class,
            errors: [],
            openapi: new Model\Operation(
                summary: 'Mark a paid cart as delivered',
                description: 'Only a paid cart can be delivered (409 otherwise). Delivery is final: a delivered cart cannot be canceled.',
                parameters: [
                    new Model\Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'Cart UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
                responses: [
                    404 => new Model\Response('No cart has this id, or it belongs to another customer.', new \ArrayObject(Problem::CONTENT)),
                    409 => new Model\Response('The cart is not paid.', new \ArrayObject(Problem::CONTENT)),
                ],
            ),
        ),
        new API\Delete(
            name: 'api_cancel_cart_by_id',
            uriTemplate: '/v1/carts/{id}',
            requirements: ['id' => Requirement::UUID],
            controller: CancelCartController::class,
            read: false,
            write: false,
            output: false,
            status: 204,
            errors: [],
            openapi: new Model\Operation(
                summary: 'Cancel a cart',
                description: 'Cancels a pending or paid cart and returns every unit its lines hold to stock. The cart stays readable with status 4 (canceled). A delivered or already canceled cart answers 409.',
                parameters: [
                    new Model\Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'Cart UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
                responses: [
                    404 => new Model\Response('No cart has this id, or it belongs to another customer.', new \ArrayObject(Problem::CONTENT)),
                    409 => new Model\Response('The cart is delivered or already canceled.', new \ArrayObject(Problem::CONTENT)),
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
            deserialize: false,
            input: false,
            output: CartRead::class,
            errors: [],
            openapi: new Model\Operation(
                summary: 'Add units of a product to a cart',
                parameters: [
                    new Model\Parameter(
                        name: 'Idempotency-Key',
                        in: 'header',
                        required: false,
                        description: 'Optional. A retry with the same key and body replays the original response (marked Idempotent-Replayed: true) instead of executing again; the same key with a different request answers 422. Keys expire after IDEMPOTENCY_TTL seconds.',
                        schema: ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    ),
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
                requestBody: new Model\RequestBody(
                    description: 'Optional. Without a body one unit is added; the units land on the line the cart already has for the product, if any.',
                    required: false,
                    content: new \ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 1],
                                ],
                            ],
                        ],
                    ]),
                ),
                responses: [
                    400 => new Model\Response('The body is present but not a JSON object, names a field this endpoint does not read, `quantity` is not an integer between 1 and 100, the line would exceed 100 units, or ' . Problem::MALFORMED_IDEMPOTENCY_KEY . '.', new \ArrayObject(Problem::CONTENT)),
                    404 => new Model\Response('No cart has this id (or it belongs to another customer), or the product does not exist or has been withdrawn.', new \ArrayObject(Problem::CONTENT)),
                    409 => new Model\Response('The cart is not pending, the product has fewer units available than requested, or the product is priced in another currency than the cart; or ' . Problem::IDEMPOTENCY_KEY_HELD . '.', new \ArrayObject(Problem::CONTENT)),
                    422 => new Model\Response(Problem::IDEMPOTENCY_KEY_REUSED, new \ArrayObject(Problem::CONTENT)),
                ],
            ),
        ),
    ],
)]
final class CartResource {}
