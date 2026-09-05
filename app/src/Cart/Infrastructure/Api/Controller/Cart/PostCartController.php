<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\CreateCartCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\Idempotency\IdempotencyGuard;
use Siroko\Cart\Infrastructure\Api\Security\CurrentCustomer;
use Siroko\Cart\Infrastructure\Api\JsonRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /v1/carts - routed by CartResource.
 *
 * Honours `Idempotency-Key`: a retry with the same key and body gets the
 * original response back instead of a second cart (see IdempotencyGuard).
 */
final class PostCartController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
        private readonly CurrentCustomer $customer,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $this->idempotency->respond($request, fn(): Response => $this->create($request));
    }

    private function create(Request $request): Response
    {
        // `json_decode(...)['products']` on an absent or malformed body reads an
        // offset off null, which PHP 8 raises as an error - so a bad request came
        // back as a 500. JsonRequest turns both cases into a 400 that says what
        // is missing; the command then validates every line as it builds.
        try {
            $jsonData = JsonRequest::toArray($request);

            $cart = $this->commandBus->handle(
                new CreateCartCommand(
                    JsonRequest::requireList($jsonData, 'products', ['productId', 'quantity']),
                    $this->customer->idOrNull(),
                ),
            );

            return new JsonResponse($cart, JsonResponse::HTTP_CREATED);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
