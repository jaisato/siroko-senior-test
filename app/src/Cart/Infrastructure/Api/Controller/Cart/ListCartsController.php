<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Cart;

use Siroko\Cart\Application\Query\Cart\ListCartsQuery;
use Siroko\Cart\Domain\CommandBus\CommandBusRead;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Siroko\Cart\Infrastructure\Api\Security\CurrentCustomer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * GET /v1/carts?pageNumber=&pageSize=&status= - routed by CartResource.
 *
 * With authentication on, the caller's own carts; without it, every cart.
 */
final class ListCartsController
{
    public const DEFAULT_PAGE_SIZE = 20;

    public function __construct(
        private readonly CommandBusRead $commandBus,
        private readonly ApiExceptionMapper $errors,
        private readonly CurrentCustomer $customer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $pageNumber = max(1, self::integerQuery($request, 'pageNumber', 1));
            $pageSize = min(ListCartsQuery::MAX_PAGE_SIZE, max(1, self::integerQuery($request, 'pageSize', self::DEFAULT_PAGE_SIZE)));
            // Absent is null, not 0. Standing for "not given" with a number the
            // caller can also send made `?status=0` - a value the operation
            // documents as a 400 - widen the answer to every cart instead of
            // being refused: the one reading of a malformed filter that returns
            // more than the valid ones do.
            $status = self::optionalIntegerQuery($request, 'status');

            if (null !== $status && !\in_array($status, [CartStatus::PENDING, CartStatus::PAID, CartStatus::DELIVERED, CartStatus::CANCELED], true)) {
                throw new BadRequestHttpException('The query parameter "status" must be 1 (pending), 2 (paid), 3 (delivered) or 4 (canceled).');
            }

            $carts = $this->commandBus->handle(
                new ListCartsQuery($this->customer->idOrNull(), $pageNumber, $pageSize, $status),
            );

            return new JsonResponse($carts);
        } catch (\Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }

    private static function integerQuery(Request $request, string $name, int $default): int
    {
        return self::optionalIntegerQuery($request, $name) ?? $default;
    }

    /**
     * The parameter as an integer, or null when it was not given at all - which
     * is a different thing from any number it could have been given as.
     */
    private static function optionalIntegerQuery(Request $request, string $name): ?int
    {
        $value = $request->query->get($name);

        if (null === $value || '' === $value) {
            return null;
        }

        if (1 !== preg_match('/^-?\d{1,9}$/', $value)) {
            throw new BadRequestHttpException(\sprintf('The query parameter "%s" must be an integer.', $name));
        }

        return (int) $value;
    }
}
