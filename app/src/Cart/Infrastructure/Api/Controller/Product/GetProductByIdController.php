<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Query\Product\GetProductByIdQuery;
use Siroko\Cart\Domain\CommandBus\CommandBusRead;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

class GetProductByIdController extends AbstractController
{
    /**
     * @param CommandBusRead $commandBusRead
     */
    public function __construct(private readonly CommandBusRead $commandBusRead)
    {
    }

    #[Route('/v1/products/{id}', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $product = $this->commandBusRead->handle(
            new GetProductByIdQuery($id)
        );

        return new JsonResponse($product);
    }
}
