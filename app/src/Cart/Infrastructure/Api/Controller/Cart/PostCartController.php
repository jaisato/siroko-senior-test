<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\CreateCartCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class PostCartController extends AbstractController
{
    /**
     * @param CommandBusWrite $commandBus
     */
    public function __construct(
        private readonly CommandBusWrite $commandBus,
    ) {
    }

    /**
     * @param Request $request
     * @return JsonResponse
     * @throws InvalidQuantityException
     */
    #[Route('/v1/carts', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $jsonData = json_decode($content, true);

        $cart = $this->commandBus->handle(
            new CreateCartCommand($jsonData['products'])
        );

        return new JsonResponse($cart, JsonResponse::HTTP_CREATED);
    }
}
