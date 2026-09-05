<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\CheckoutCartCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyGuard;
use Siroko\Cart\Infrastructure\Api\Security\CurrentCustomer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PUT /v1/carts/{id}/checkout - routed by CartResource.
 *
 * Answers `{"cart": ..., "order": ...}`: the paid cart and the order placed
 * for it. The cart alone used to be the whole answer, which left the client
 * with a paid cart and no record of what had been captured.
 *
 * Honours `Idempotency-Key`: a retry with the same key gets the same order
 * back rather than the 409 a second checkout would otherwise earn.
 */
final class CheckoutCartController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
        private readonly CurrentCustomer $customer,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    public function __invoke(string $id, Request $request): Response
    {
        return $this->idempotency->respond($request, fn(): Response => $this->checkout($id));
    }

    private function checkout(string $id): Response
    {
        try {
            $cart = $this->commandBus->handle(
                new CheckoutCartCommand($id, $this->customer->idOrNull()),
            );

            return new JsonResponse($cart);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
