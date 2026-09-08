<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Product;

use Siroko\Cart\Application\Query\Product\GetProductListQuery;
use Siroko\Cart\Domain\CommandBus\CommandBusRead;
use Siroko\Cart\Domain\Exception\InvalidProductCriteriaException;
use Siroko\Cart\Domain\Repository\ProductCriteria;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * GET /v1/products?pageNumber=&pageSize=&q=&minPrice=&maxPrice=&inStock=&sort=
 * - routed by ProductResource.
 */
final class GetProductListController
{
    /**
     * A page of one product was the previous default, which made the list
     * endpoint useless without query parameters.
     */
    public const DEFAULT_PAGE_SIZE = 20;

    public function __construct(
        private readonly CommandBusRead $commandBusRead,
        private readonly ApiExceptionMapper $errors,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            // Integers are clamped to the bounds the query declares - page 1 or
            // later, at most MAX_PAGE_SIZE rows - so `?pageSize=1000000` gets a
            // full page rather than the whole table. The query itself is the
            // one place that enforces those bounds.
            $pageNumber = max(1, self::integerQuery($request, 'pageNumber', 1));
            $pageSize = min(GetProductListQuery::MAX_PAGE_SIZE, max(1, self::integerQuery($request, 'pageSize', self::DEFAULT_PAGE_SIZE)));

            $products = $this->commandBusRead->handle(
                new GetProductListQuery($pageNumber, $pageSize, self::criteria($request)),
            );

            return new JsonResponse($products);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }

    /**
     * The filters are validated by ProductCriteria; an unknown filter name is
     * ignored, an unusable value of a known one is a 400.
     */
    private static function criteria(Request $request): ProductCriteria
    {
        return ProductCriteria::of(
            text: self::stringQuery($request, 'q'),
            minPrice: self::stringQuery($request, 'minPrice'),
            maxPrice: self::stringQuery($request, 'maxPrice'),
            inStock: self::booleanQuery($request, 'inStock'),
            sort: self::stringQuery($request, 'sort'),
        );
    }

    private static function stringQuery(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);

        return null === $value || '' === $value ? null : $value;
    }

    private static function booleanQuery(Request $request, string $name): ?bool
    {
        $value = self::stringQuery($request, $name);

        return match ($value) {
            null => null,
            'true', '1' => true,
            'false', '0' => false,
            default => throw InvalidProductCriteriaException::notABoolean($name),
        };
    }

    /**
     * `InputBag::getInt()` throws on a value it cannot parse, which used to
     * surface as a 500; a query string is the caller's, so it gets a 400 that
     * names the parameter.
     */
    private static function integerQuery(Request $request, string $name, int $default): int
    {
        $value = $request->query->get($name);

        if (null === $value || '' === $value) {
            return $default;
        }

        if (1 !== preg_match('/^-?\d{1,9}$/', $value)) {
            throw new BadRequestHttpException(\sprintf('The query parameter "%s" must be an integer.', $name));
        }

        return (int) $value;
    }
}
