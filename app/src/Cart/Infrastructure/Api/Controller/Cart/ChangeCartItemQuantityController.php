<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\ChangeCartItemQuantityCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\Security\CurrentCustomer;
use Siroko\Cart\Infrastructure\Api\JsonRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * PATCH /v1/carts/{cartId}/items/{itemId} - routed by CartResource.
 *
 * Body: {"quantity": n}. Sets the line to n units, reserving or returning the
 * difference; 0 removes the line. Answers the whole cart, so the client sees
 * the line it changed next to the ones it did not.
 */
final class ChangeCartItemQuantityController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
        private readonly CurrentCustomer $customer,
    ) {}

    public function __invoke(string $cartId, string $itemId, Request $request): JsonResponse
    {
        try {
            $body = JsonRequest::toArray($request);
            JsonRequest::rejectUnknownFields($body, ['quantity']);

            $cart = $this->commandBus->handle(
                new ChangeCartItemQuantityCommand($cartId, $itemId, JsonRequest::requireInt($body, 'quantity'), $this->customer->idOrNull()),
            );

            return new JsonResponse($cart);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
