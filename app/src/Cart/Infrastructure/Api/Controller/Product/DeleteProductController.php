<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Command\Product\DeleteProductCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpFoundation\Response;

/**
 * DELETE /v1/products/{id} - routed by ProductResource.
 *
 * Withdraws the product from the catalogue. Carts that hold it keep their
 * lines; orders keep their snapshot; the product simply stops being found.
 */
final class DeleteProductController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(string $id): Response
    {
        try {
            $this->commandBus->handle(
                new DeleteProductCommand($id),
            );
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
