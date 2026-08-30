<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\CheckoutCartCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class CheckoutCartController extends AbstractController
{
    /**
     * @param CommandBusWrite $commandBus
     */
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {
    }

    /**
     * @param string $id
     * @return JsonResponse
     */
    #[Route('/v1/carts/{id}/checkout', methods: ['PUT'])]
    public function __invoke(string $id): JsonResponse
    {
        try {
            $cart = $this->commandBus->handle(
                new CheckoutCartCommand($id)
            );

            return new JsonResponse($cart);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
