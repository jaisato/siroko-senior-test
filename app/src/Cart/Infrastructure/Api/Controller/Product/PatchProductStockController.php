<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Command\Product\AdjustProductStockCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\JsonRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * PATCH /v1/products/{id}/stock - routed by ProductResource.
 *
 * Body: `{"quantity": n}` sets the available stock; `{"delta": n}` adds
 * (or, negative, removes) units. Exactly one of the two.
 */
final class PatchProductStockController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(string $id, Request $request): JsonResponse
    {
        try {
            $body = JsonRequest::toArray($request);
            JsonRequest::rejectUnknownFields($body, ['quantity', 'delta']);

            $product = $this->commandBus->handle(
                new AdjustProductStockCommand(
                    $id,
                    \array_key_exists('quantity', $body) ? JsonRequest::requireInt($body, 'quantity') : null,
                    \array_key_exists('delta', $body) ? JsonRequest::requireInt($body, 'delta') : null,
                ),
            );

            return new JsonResponse($product);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
