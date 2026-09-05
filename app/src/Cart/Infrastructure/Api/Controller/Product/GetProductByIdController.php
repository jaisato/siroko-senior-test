<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Query\Product\GetProductByIdQuery;
use Siroko\Cart\Domain\CommandBus\CommandBusRead;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /v1/products/{id} - routed by ProductResource.
 */
final class GetProductByIdController
{
    public function __construct(
        private readonly CommandBusRead $commandBusRead,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        try {
            $product = $this->commandBusRead->handle(
                new GetProductByIdQuery($id),
            );

            return new JsonResponse($product);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
