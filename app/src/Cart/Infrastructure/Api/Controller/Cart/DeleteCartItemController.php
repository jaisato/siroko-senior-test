<?php

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\DeleteCartItemCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Annotation\Route;

class DeleteCartItemController extends AbstractController
{
    /**
     * @param CommandBusWrite $commandBus
     */
    public function __construct(
        private readonly CommandBusWrite $commandBus,
    ) {
    }

    /**
     * @param string $cartId
     * @param string $itemId
     * @return JsonResponse
     */
    #[Route('/v1/carts/{cartId}/{itemId}', methods: ['DELETE'])]
    public function __invoke(string $cartId, string $itemId): JsonResponse
    {
        try {
            $this->commandBus->handle(
                new DeleteCartItemCommand($cartId, $itemId)
            );
        } catch (HttpExceptionInterface $ex) {
            // Preserve intended HTTP status codes (e.g. 404 for a missing item).
            throw $ex;
        } catch (\Throwable $ex) {
            return new JsonResponse(['error' => 'Internal server error'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }
}
