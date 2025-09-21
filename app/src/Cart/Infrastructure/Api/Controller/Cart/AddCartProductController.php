<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\AddCartProductCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class AddCartProductController extends AbstractController
{
    /**
     * @param CommandBusWrite $commandBus
     */
    public function __construct(
        private readonly CommandBusWrite $commandBus,
    ) {
    }

    /**
     * @param string $cartId
     * @param string $productId
     * @return JsonResponse
     */
    #[Route('/v1/carts/{cartId}/products/{productId}/add', methods: ['PUT'])]
    public function __invoke(string $cartId, string $productId): JsonResponse
    {
        try {
            $cart = $this->commandBus->handle(
                new AddCartProductCommand($cartId, $productId)
            );

            return new JsonResponse($cart);
        } catch (\Throwable $e) {
            return new JsonResponse(['Exception' => $e->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
