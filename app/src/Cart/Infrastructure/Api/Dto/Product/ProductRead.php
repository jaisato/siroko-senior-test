<?php

namespace Siroko\Cart\Infrastructure\Api\Dto\Product;

use ApiPlatform\Metadata as API;
use ApiPlatform\OpenApi\Model;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Infrastructure\Api\Controller\Product\GetProductByIdController;
use Siroko\Cart\Infrastructure\Api\Controller\Product\PostProductController;

#[API\ApiResource(
    operations: [
        new API\Get(
            uriTemplate: '/v1/products/{id}',
            controller: GetProductByIdController::class,
            read: false,
            output: ProductRead::class,
            openapi: new Model\Operation(
                summary: 'Get product by id',
                parameters: [
                    new Model\Parameter(
                        name: 'id', in: 'path', required: true,
                        description: 'Product UUID', schema: ['type' => 'string', 'format' => 'uuid']
                    ),
                ]
            ),
        ),
        new API\Post(
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
                                'required' => ['name','code','priceAmount', 'priceCurrency', 'quantity'],
                                'properties' => [
                                    'name'  => ['type' => 'string', 'maxLength' => 200],
                                    'code'  => ['type' => 'string', 'maxLength' => 50],
                                    'priceAmount' => ['type' => 'string', 'example' => '19.99'],
                                    'priceCurrency' => ['type' => 'string', 'example' => 'EUR'],
                                    'quantity' => ['type' => 'integer', 'example' => 1],
                                ],
                            ],
                        ],
                    ])
                ),
            ),
        ),
    ],
)]
class ProductRead
{
    /**
     * @param string $id
     * @param string $name
     * @param string $code
     * @param string $price
     * @param int $quantity
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $code,
        public readonly string $price,
        public readonly int $quantity
    ) {
    }

    /**
     * @param Product $product
     * @return self
     * @throws \Brick\Money\Exception\UnknownCurrencyException
     */
    public static function fromModel(Product $product): self
    {
        return new self(
            $product->id()->toString(),
            $product->name()->toString(),
            $product->code()->toString(),
            $product->price()->toMoney()->formatTo('es_ES'),
            $product->quantity()->asInt()
        );
    }
}
