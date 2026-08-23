<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\CreateCartCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Infrastructure\Api\JsonRequest;
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
        // `json_decode(...)['products']` on an absent or malformed body reads an
        // offset off null, which PHP 8 raises as an error - so a bad request came
        // back as a 500. JsonRequest turns both cases into a 400 that says what
        // is missing.
        $jsonData = JsonRequest::toArray($request);

        $cart = $this->commandBus->handle(
            new CreateCartCommand(JsonRequest::requireList($jsonData, 'products'))
        );

        return new JsonResponse($cart, JsonResponse::HTTP_CREATED);
    }
}
