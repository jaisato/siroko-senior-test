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
    private const MAX_PAGE_SIZE = 100;

    /** Unchanged from before, so a caller that sends no pageSize sees no change. */
    private const DEFAULT_PAGE_SIZE = 1;

    /**
     * @param CommandBusRead $commandBusRead
     */
    public function __construct(private readonly CommandBusRead $commandBusRead)
    {
    }

    #[Route('/v1/products', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        // pageSize was whatever the caller sent, so ?pageSize=1000000 pulled the
        // whole products table into memory and serialised it in one response.
        // The page is clamped instead: page 1 or later, at most MAX_PAGE_SIZE
        // rows.
        $pageNumber = max(1, $request->query->getInt('pageNumber', 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $request->query->getInt('pageSize', self::DEFAULT_PAGE_SIZE)));

        $products = $this->commandBusRead->handle(
            new GetProductListQuery($pageNumber, $pageSize)
        );

        return new JsonResponse($products);
    }
}
