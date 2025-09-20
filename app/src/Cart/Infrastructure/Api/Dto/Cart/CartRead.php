<?php

namespace Siroko\Cart\Infrastructure\Api\Dto\Cart;

use ApiPlatform\Metadata as API;
use ApiPlatform\OpenApi\Model;
use Brick\Money\Exception\UnknownCurrencyException;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\GetCartController;
use Siroko\Cart\Infrastructure\Api\Controller\Cart\PostCartController;

#[API\ApiResource(
    operations: [
        new API\Get(
            uriTemplate: '/v1/carts/{id}',
            controller: GetCartController::class,
            read: false,
            output: CartRead::class,
            openapi: new Model\Operation(
                summary: 'Get cart by id',
                parameters: [
                    new Model\Parameter(
                        name: 'id', in: 'path', required: true,
                        description: 'Cart UUID', schema: ['type' => 'string', 'format' => 'uuid']
                    ),
                ]
            ),
        ),
        new API\Post(
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
                                        'items' => [
                                            'type' => 'object',
                                            'required' => ['productId', 'quantity'],
                                            'additionalProperties' => false,
                                            'properties' => [
                                                'productId' => ['type' => 'string', 'format' => 'uuid'],
                                                'quantity'  => ['type' => 'integer', 'minimum' => 1],
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
                                            ['productId' => '0190aa11-bb22-cc33-dd44-ee5566778899', 'quantity' => 1],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ])
                ),
            ),
        ),
    ],
)]
final class CartRead
{
    /**
     * @param string $id
     * @param int $status
     * @param array|CartItemRead[] $items
     * @throws UnknownCurrencyException
     */
    public function __construct(
        public readonly string $id,
        public readonly int $status,
        public array $items = [],
    ) {
        $this->setItems($items);
    }

    /**
     * @param array $items
     * @return void
     * @throws UnknownCurrencyException
     */
    private function setItems(array $items): void
    {
        $this->items = [];
        foreach ($items as $item) {
            if ($item instanceof CartItem) {
                $this->items[$item->id()->toString()] = CartItemRead::fromModel($item);
            }
        }
    }

    /**
     * @param Cart $cart
     * @return self
     * @throws UnknownCurrencyException
     */
    public static function fromModel(Cart $cart): self
    {
        return new self(
            $cart->id()->toString(),
            $cart->status()->toInt(),
            $cart->items()->toArray(),
        );
    }
}
