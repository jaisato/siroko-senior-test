<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Command\Product\CreateProductCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\JsonRequest;
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
        // Each of these five keys was read straight off the decoded body, so a
        // request missing any one of them raised "Undefined array key" - an
        // error in PHP 8 - and the caller got a 500 for what is a bad request.
        $jsonContent = JsonRequest::toArray($request);
        $product = $this->commandBus->handle(
            new CreateProductCommand(
                JsonRequest::requireField($jsonContent, 'code'),
                JsonRequest::requireField($jsonContent, 'name'),
                JsonRequest::requireField($jsonContent, 'priceAmount'),
                JsonRequest::requireField($jsonContent, 'priceCurrency'),
                JsonRequest::requireField($jsonContent, 'quantity')
            )
        );
        return new JsonResponse($product, Response::HTTP_CREATED);
    }
}
