<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Order;

use Siroko\Cart\Application\Query\Order\GetOrderByIdQuery;
use Siroko\Cart\Domain\CommandBus\CommandBusRead;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /v1/orders/{id} - routed by OrderResource.
 */
final class GetOrderController
{
    public function __construct(
        private readonly CommandBusRead $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        try {
            $order = $this->commandBus->handle(
                new GetOrderByIdQuery($id),
            );

            return new JsonResponse($order);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
