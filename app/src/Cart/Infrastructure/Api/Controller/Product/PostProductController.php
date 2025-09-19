<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Command\Product\CreateProductCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

class PostProductController extends AbstractController
{
    /**
     * @param CommandBusWrite $commandBus
     */
    public function __construct(
        private readonly CommandBusWrite $commandBus
    ) {
    }

    #[Route('/v1/products', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $requestContent = $request->getContent();
        $jsonContent = json_decode($requestContent, true);
        $product = $this->commandBus->handle(
            new CreateProductCommand(
                $jsonContent['code'],
                $jsonContent['name'],
                $jsonContent['priceAmount'],
                $jsonContent['priceCurrency'],
                $jsonContent['quantity']
            )
        );
        return new JsonResponse($product, Response::HTTP_CREATED);
    }
}
