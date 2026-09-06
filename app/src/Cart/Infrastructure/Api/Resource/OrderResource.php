<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Resource;

use ApiPlatform\Metadata as API;
use ApiPlatform\OpenApi\Model;
use Siroko\Cart\Application\Dto\Order\OrderRead;
use Siroko\Cart\Infrastructure\Api\Controller\Order\GetOrderController;
use Siroko\Cart\Infrastructure\Api\OpenApi\Problem;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Routing and OpenAPI metadata of the order endpoints; see CartResource for
 * why the controllers carry no routes of their own and how the error
 * responses are declared. Orders are created by the checkout of a cart,
 * never directly.
 */
#[API\ApiResource(
    shortName: 'Order',
    description: 'The record a checkout leaves behind: the lines as they were paid and the captured total. An order is placed by paying a cart, never created directly.',
    operations: [
        new API\Get(
            name: 'api_get_order_by_id',
            uriTemplate: '/v1/orders/{id}',
            requirements: ['id' => Requirement::UUID],
            controller: GetOrderController::class,
            read: false,
            output: OrderRead::class,
            errors: [],
            openapi: new Model\Operation(
                summary: 'Get order by id',
                description: 'The record a checkout left behind: the lines as they were paid, the captured total, and when the confirmation went out (`confirmedAt`, set by the worker).',
                parameters: [
                    new Model\Parameter(
                        name: 'id',
                        in: 'path',
                        required: true,
                        description: 'Order UUID, as returned by the checkout',
                        schema: ['type' => 'string', 'format' => 'uuid'],
                    ),
                ],
                responses: [
                    404 => new Model\Response('No order has this id, or it belongs to another customer.', new \ArrayObject(Problem::CONTENT)),
                ],
            ),
        ),
    ],
)]
final class OrderResource {}
