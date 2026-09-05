<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\CancelCartCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpFoundation\Response;

/**
 * DELETE /v1/carts/{id} - routed by CartResource.
 *
 * Cancels the cart and returns every reserved unit to stock. The cart row
 * stays, as a canceled cart; a client that reads it afterwards sees why its
 * lines no longer hold anything.
 */
final class CancelCartController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(string $id): Response
    {
        try {
            $this->commandBus->handle(
                new CancelCartCommand($id),
            );
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
