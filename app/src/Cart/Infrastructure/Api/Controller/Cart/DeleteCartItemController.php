<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class DeleteCartItemController extends AbstractController
{
    /**
     * @param string $cartId
     * @param string $itemId
     * @return JsonResponse
     */
    #[Route('/v1/carts/{cartId}/{itemId}', methods: ['DELETE'])]
    public function __invoke(string $cartId, string $itemId): JsonResponse
    {
        return new JsonResponse([]);
    }
}
