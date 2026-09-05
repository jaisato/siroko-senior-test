<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Resource;

use ApiPlatform\Metadata as API;
use ApiPlatform\OpenApi\Model;
use Siroko\Cart\Application\Dto\Product\ProductRead;
use Siroko\Cart\Application\Dto\Product\ProductReadCollection;
use Siroko\Cart\Infrastructure\Api\Controller\Product\DeleteProductController;
use Siroko\Cart\Infrastructure\Api\Controller\Product\GetProductByCodeController;
use Siroko\Cart\Infrastructure\Api\Controller\Product\GetProductByIdController;
use Siroko\Cart\Infrastructure\Api\Controller\Product\GetProductListController;
use Siroko\Cart\Infrastructure\Api\Controller\Product\PatchProductController;
use Siroko\Cart\Infrastructure\Api\Controller\Product\PatchProductStockController;
use Siroko\Cart\Infrastructure\Api\Controller\Product\PostProductController;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Routing and OpenAPI metadata of the product endpoints; see CartResource for
 * why the controllers carry no routes of their own.
 */
#[API\ApiResource(
    shortName: 'Product',
    operations: [
        new API\Get(
            name: 'api_get_product_by_id',
            uriTemplate: '/v1/products/{id}',
            requirements: ['id' => Requirement::UUID],
            controller: GetProductByIdController::class,
            read: false,
            output: ProductRead::class,
            openapi: new Model\Operation(
                summary: 'Get product by id',
                parameters: [
                    new Model\Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'Product UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
            ),
        ),
        new API\Get(
            name: 'api_get_product_by_code',
            uriTemplate: '/v1/products/by-code/{code}',
            requirements: ['code' => '[^/]{1,50}'],
            controller: GetProductByCodeController::class,
            read: false,
            output: ProductRead::class,
            openapi: new Model\Operation(
                summary: 'Get product by code',
                parameters: [
                    new Model\Parameter(
                        name: 'code',
                        in: 'path',
                        required: true,
                        description: 'Product code (1-50 characters)',
                        schema: ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                    ),
                ],
            ),
        ),
        new API\GetCollection(
            name: 'api_get_products',
            uriTemplate: '/v1/products',
            controller: GetProductListController::class,
            read: false,
            output: ProductReadCollection::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'List products',
                description: 'One page of the catalogue with pagination metadata (page, pageSize, total, pages). Withdrawn products are not listed. Filters combine with AND; prices are compared by amount.',
                parameters: [
                    new Model\Parameter(name: 'pageNumber', in: 'query', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
                    new Model\Parameter(name: 'pageSize', in: 'query', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]),
                    new Model\Parameter(name: 'q', in: 'query', description: 'Case-insensitive text contained in the name or the code', schema: ['type' => 'string', 'maxLength' => 100]),
                    new Model\Parameter(name: 'minPrice', in: 'query', description: 'Inclusive lower bound of the amount', schema: ['type' => 'string', 'example' => '10.00']),
                    new Model\Parameter(name: 'maxPrice', in: 'query', description: 'Inclusive upper bound of the amount', schema: ['type' => 'string', 'example' => '99.99']),
                    new Model\Parameter(name: 'inStock', in: 'query', description: 'true: units available; false: none', schema: ['type' => 'boolean']),
                    new Model\Parameter(name: 'sort', in: 'query', description: 'Field to order by; a leading "-" sorts descending', schema: ['type' => 'string', 'enum' => ['name', '-name', 'price', '-price', 'code', '-code'], 'default' => 'name']),
                ],
            ),
        ),
        new API\Patch(
            name: 'api_update_product',
            uriTemplate: '/v1/products/{id}',
            requirements: ['id' => Requirement::UUID],
            controller: PatchProductController::class,
            read: false,
            write: false,
            deserialize: false,
            input: false,
            output: ProductRead::class,
            openapi: new Model\Operation(
                summary: 'Update a product',
                description: 'Changes any of name, code and price. Stock has its own endpoint. A code already in use answers 409.',
                parameters: [
                    new Model\Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'Product UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
                requestBody: new Model\RequestBody(
                    description: 'At least one of the properties',
                    required: true,
                    content: new \ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'minProperties' => 1,
                                'additionalProperties' => false,
                                'properties' => [
                                    'name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200],
                                    'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                                    'price' => [
                                        'type' => 'object',
                                        'required' => ['amount', 'currency'],
                                        'properties' => [
                                            'amount' => ['type' => 'string', 'example' => '19.99'],
                                            'currency' => ['type' => 'string', 'example' => 'EUR'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
        ),
        new API\Patch(
            name: 'api_adjust_product_stock',
            uriTemplate: '/v1/products/{id}/stock',
            requirements: ['id' => Requirement::UUID],
            controller: PatchProductStockController::class,
            read: false,
            write: false,
            deserialize: false,
            input: false,
            output: ProductRead::class,
            openapi: new Model\Operation(
                summary: 'Adjust the available stock of a product',
                description: 'Exactly one of `quantity` (sets the available units) or `delta` (adds units; negative removes them). The available count never goes below zero: a delta that would answers 409.',
                parameters: [
                    new Model\Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'Product UUID',
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
                                'minProperties' => 1,
                                'maxProperties' => 1,
                                'additionalProperties' => false,
                                'properties' => [
                                    'quantity' => ['type' => 'integer', 'minimum' => 0],
                                    'delta' => ['type' => 'integer', 'description' => 'Non-zero; negative removes units'],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
        ),
        new API\Delete(
            name: 'api_delete_product',
            uriTemplate: '/v1/products/{id}',
            requirements: ['id' => Requirement::UUID],
            controller: DeleteProductController::class,
            read: false,
            write: false,
            output: false,
            status: 204,
            openapi: new Model\Operation(
                summary: 'Withdraw a product from the catalogue',
                description: 'Soft delete: the product stops being listed and found, carts can no longer add it, but existing cart lines and orders keep referencing it. Its code stays taken.',
                parameters: [
                    new Model\Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'Product UUID',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
            ),
        ),
        new API\Post(
            name: 'api_create_product',
            uriTemplate: '/v1/products',
            controller: PostProductController::class,
            read: false,
            output: ProductRead::class,
            openapi: new Model\Operation(
                summary: 'Create product',
                requestBody: new Model\RequestBody(
                    description: 'JSON payload',
                    required: true,
                    content: new \ArrayObject([
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['name', 'code', 'priceAmount', 'priceCurrency', 'quantity'],
                                'properties' => [
                                    'name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200],
                                    'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                                    'priceAmount' => ['type' => 'string', 'example' => '19.99'],
                                    'priceCurrency' => ['type' => 'string', 'example' => 'EUR'],
                                    'quantity' => ['type' => 'integer', 'minimum' => 0, 'example' => 1],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
        ),
    ],
)]
final class ProductResource {}
