<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Resource;

use ApiPlatform\Metadata as API;
use ApiPlatform\OpenApi\Model;
use Siroko\Cart\Application\Dto\Product\ProductRead;
use Siroko\Cart\Application\Dto\Product\ProductReadCollection;
use Siroko\Cart\Infrastructure\Api\Controller\Product\GetProductByIdController;
use Siroko\Cart\Infrastructure\Api\Controller\Product\GetProductListController;
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
        new API\GetCollection(
            name: 'api_get_products',
            uriTemplate: '/v1/products',
            controller: GetProductListController::class,
            read: false,
            output: ProductReadCollection::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'List products',
                description: 'One page of the catalogue, ordered by name, with pagination metadata (page, pageSize, total, pages).',
                parameters: [
                    new Model\Parameter(name: 'pageNumber', in: 'query', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
                    new Model\Parameter(name: 'pageSize', in: 'query', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]),
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
