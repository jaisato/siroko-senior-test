<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\AddCartProductCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * PUT /v1/carts/{cartId}/products/{productId}/add - routed by CartResource.
 */
final class AddCartProductController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(string $cartId, string $productId): JsonResponse
    {
        try {
            $cart = $this->commandBus->handle(
                new AddCartProductCommand($cartId, $productId),
            );

            return new JsonResponse($cart);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
