<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\CheckoutCartCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * PUT /v1/carts/{id}/checkout - routed by CartResource.
 */
final class CheckoutCartController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        try {
            $cart = $this->commandBus->handle(
                new CheckoutCartCommand($id),
            );

            return new JsonResponse($cart);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
