<?php

namespace Siroko\Cart\Infrastructure\Api\Dto\Product;

use ApiPlatform\Metadata as API;
use ApiPlatform\OpenApi\Model;
use Brick\Money\Exception\UnknownCurrencyException;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Infrastructure\Api\Controller\Product\GetProductListController;

#[API\ApiResource(
    operations: [
        new API\GetCollection(
            name: 'api_get_products',
            uriTemplate: '/v1/products',
            controller: GetProductListController::class,
            read: false,
            output: ProductReadCollection::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'List products',
                parameters: [
                    new Model\Parameter(name: 'pageNumber', in: 'query', schema: ['type' => 'integer', 'minimum' => 1]),
                    new Model\Parameter(name: 'pageSize', in: 'query', schema: ['type' => 'integer', 'minimum' => 1]),
                ]
            ),
        ),
    ],
)]
final class ProductReadCollection
{
    /**
     * @var array|ProductRead[]
     */
    public array $products;

    /**
     * @param array|Product[] $products
     * @return self
     * @throws UnknownCurrencyException
     */
    public static function fromArray(array $products): self
    {
        $productReadCollection = new self();
        foreach ($products as $product) {
            $productReadCollection->products[] = ProductRead::fromModel($product);
        }

        return $productReadCollection;
    }
}
