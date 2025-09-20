<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Query\Product\GetProductListQuery;
use Siroko\Cart\Domain\CommandBus\CommandBusRead;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class GetProductListController extends AbstractController
{
    /**
     * @param CommandBusRead $commandBusRead
     */
    public function __construct(private readonly CommandBusRead $commandBusRead)
    {
    }

    #[Route('/v1/products', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $products = $this->commandBusRead->handle(
            new GetProductListQuery(
                $request->query->getInt('pageNumber', 1),
                $request->query->getInt('pageSize', 1)
            )
        );

        return new JsonResponse($products);
    }
}
