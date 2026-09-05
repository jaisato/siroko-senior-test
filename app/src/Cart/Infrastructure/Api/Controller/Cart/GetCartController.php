<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Query\Cart\GetCartByIdQuery;
use Siroko\Cart\Domain\CommandBus\CommandBusRead;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /v1/carts/{id} - routed by CartResource.
 */
final class GetCartController
{
    public function __construct(
        private readonly CommandBusRead $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        // The read controllers had no error handling at all, so the handler's
        // "cart not found" - and any other failure - surfaced as a generic 500.
        try {
            $cart = $this->commandBus->handle(
                new GetCartByIdQuery($id),
            );

            return new JsonResponse($cart);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
