<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Command\Product\UpdateProductCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Domain\Exception\InvalidProductUpdateException;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\JsonRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * PATCH /v1/products/{id} - routed by ProductResource.
 *
 * Body: any of `name`, `code`, `price: {amount, currency}`. Stock has its own
 * endpoint: it moves, the rest is edited.
 */
final class PatchProductController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(string $id, Request $request): JsonResponse
    {
        try {
            $body = JsonRequest::toArray($request);
            [$amount, $currency] = self::price($body);

            $product = $this->commandBus->handle(
                new UpdateProductCommand(
                    $id,
                    \array_key_exists('name', $body) ? JsonRequest::requireString($body, 'name') : null,
                    \array_key_exists('code', $body) ? JsonRequest::requireString($body, 'code') : null,
                    $amount,
                    $currency,
                ),
            );

            return new JsonResponse($product);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function price(array $body): array
    {
        if (!\array_key_exists('price', $body)) {
            return [null, null];
        }

        $price = $body['price'];

        if (!\is_array($price) || !\array_key_exists('amount', $price) || !\array_key_exists('currency', $price)) {
            throw InvalidProductUpdateException::priceNeedsAmountAndCurrency();
        }

        /** @var array<string, mixed> $price */
        return [JsonRequest::requireString($price, 'amount'), JsonRequest::requireString($price, 'currency')];
    }
}
