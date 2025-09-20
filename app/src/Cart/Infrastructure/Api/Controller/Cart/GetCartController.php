<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Query\Cart\GetCartByIdQuery;
use Siroko\Cart\Domain\CommandBus\CommandBusRead;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class GetCartController extends AbstractController
{
    /**
     * @param CommandBusRead $commandBus
     */
    public function __construct(
        private readonly CommandBusRead $commandBus,
    ) {
    }

    /**
     * @param string $id
     * @return JsonResponse
     */
    #[Route('/v1/carts/{id}', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $cart = $this->commandBus->handle(
            new GetCartByIdQuery($id)
        );
        return new JsonResponse($cart);
    }
}
