<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Command\Product\CreateProductCommand;
use Siroko\Cart\Domain\CommandBus\CommandBusWrite;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\JsonRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /v1/products - routed by ProductResource.
 */
final class PostProductController
{
    public function __construct(
        private readonly CommandBusWrite $commandBus,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Each of these five keys was read straight off the decoded body, so a
        // request missing any one of them raised "Undefined array key" - an
        // error in PHP 8 - and the caller got a 500 for what is a bad request.
        // The typed accessors also stop an array or a boolean from reaching a
        // value object constructor as a TypeError.
        try {
            $jsonContent = JsonRequest::toArray($request);
            $product = $this->commandBus->handle(
                new CreateProductCommand(
                    JsonRequest::requireString($jsonContent, 'code'),
                    JsonRequest::requireString($jsonContent, 'name'),
                    JsonRequest::requireString($jsonContent, 'priceAmount'),
                    JsonRequest::requireString($jsonContent, 'priceCurrency'),
                    JsonRequest::requireInt($jsonContent, 'quantity'),
                ),
            );

            return new JsonResponse($product, Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }
}
