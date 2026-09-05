<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\DeleteCartItemCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpFoundation\Response;

/**
 * DELETE /v1/carts/{cartId}/items/{itemId} - routed by CartResource.
 *
 * The path names the sub-collection: `/v1/carts/{cartId}/{itemId}` read as
 * though the item were a property of the cart, and left no room for any other
 * sub-resource of a cart.
 */
final class DeleteCartItemController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(string $cartId, string $itemId): Response
    {
        try {
            $this->commandBus->handle(
                new DeleteCartItemCommand($cartId, $itemId),
            );
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }

        // 204 carries no body: `new JsonResponse([])` sent "[]" with it.
        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
