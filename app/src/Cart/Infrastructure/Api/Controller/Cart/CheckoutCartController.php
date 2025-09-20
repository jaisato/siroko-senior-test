<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class CheckoutCartController extends AbstractController
{
    /**
     * @param string $id
     * @return JsonResponse
     */
    #[Route('/v1/carts/{id}/checkout', methods: ['PUT'])]
    public function __invoke(string $id): JsonResponse
    {
        return new JsonResponse([]);
    }
}
