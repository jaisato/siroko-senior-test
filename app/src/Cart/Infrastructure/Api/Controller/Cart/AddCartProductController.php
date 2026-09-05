<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Command\Cart\AddCartProductCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\Security\CurrentCustomer;
use Siroko\Cart\Infrastructure\Api\JsonRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * PUT /v1/carts/{cartId}/products/{productId}/add - routed by CartResource.
 *
 * The body is optional. Without one, a single unit is added, as this endpoint
 * has always done; `{"quantity": n}` adds n units in one go. Either way the
 * units land on the line the cart already has for the product, if any.
 */
final class AddCartProductController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
        private readonly CurrentCustomer $customer,
    ) {}

    public function __invoke(string $cartId, string $productId, Request $request): JsonResponse
    {
        try {
            $cart = $this->commandBus->handle(
                new AddCartProductCommand($cartId, $productId, self::quantity($request), $this->customer->idOrNull()),
            );

            return new JsonResponse($cart);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }

    private static function quantity(Request $request): int
    {
        if ('' === trim($request->getContent())) {
            return CartItem::MIN_QUANTITY;
        }

        $body = JsonRequest::toArray($request);

        if (!\array_key_exists('quantity', $body)) {
            return CartItem::MIN_QUANTITY;
        }

        return JsonRequest::requireInt($body, 'quantity');
    }
}
