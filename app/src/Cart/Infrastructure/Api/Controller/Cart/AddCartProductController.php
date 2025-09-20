<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class AddCartProductController extends AbstractController
{
    /**
     * @param string $cartId
     * @param string $productId
     * @return JsonResponse
     */
    #[Route('/v1/carts/{cartId}/products/{productId}', methods: ['PUT'])]
    public function __invoke(string $cartId, string $productId): JsonResponse
    {
        return new JsonResponse([]);
    }
}
