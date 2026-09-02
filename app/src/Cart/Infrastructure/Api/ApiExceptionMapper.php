<?php

namespace Siroko\Cart\Infrastructure\Api;

use Psr\Log\LoggerInterface;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidProductCodeException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Exception\NameInvalidLengthException;
use Siroko\Cart\Domain\Exception\OutOfStockException;
use Siroko\Cart\Domain\Exception\PriceIsNotSameCurrencyException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Single place that turns an exception into the API's error response.
 *
 * Every controller used to end in `catch (\Throwable $e)` returning
 * `['exception' => $e->getMessage()]` with HTTP 500. That was wrong in two
 * separate ways:
 *
 * - The status code was 500 for everything. Handlers already throw
 *   NotFoundHttpException for a cart or product that does not exist, and the
 *   domain throws its own exceptions for a rejected quantity or a cart that is
 *   already checked out - all of which reached the client as "the server
 *   broke". A caller could not tell a bad request from an outage, and no client
 *   or monitor could act on the difference.
 * - The message was echoed verbatim. For an unexpected failure that message is
 *   whatever the ORM, the driver or the queue produced: SQL fragments, file
 *   paths, host names, and in the case of a connection failure the DSN itself.
 *
 * Deliberate exceptions carry their own status and a message written to be
 * read. Anything else is a bug, and its message stays in the log.
 */
final class ApiExceptionMapper
{
    /**
     * Domain exceptions the API can report as a rejected request rather than a
     * failure. The domain raises them for input it will not accept, so they are
     * the caller's problem and their messages are safe to return.
     *
     * @var array<class-string<\Throwable>, int>
     */
    private const DOMAIN_STATUS = [
        InvalidCartStatusException::class      => Response::HTTP_CONFLICT,
        InvalidQuantityException::class        => Response::HTTP_BAD_REQUEST,
        InvalidProductCodeException::class     => Response::HTTP_BAD_REQUEST,
        NameInvalidLengthException::class      => Response::HTTP_BAD_REQUEST,
        OutOfStockException::class             => Response::HTTP_CONFLICT,
        PriceIsNotSameCurrencyException::class => Response::HTTP_BAD_REQUEST,
    ];

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function toResponse(\Throwable $e): JsonResponse
    {
        if ($e instanceof HttpExceptionInterface) {
            return $this->error($e->getMessage(), $e->getStatusCode(), $e->getHeaders());
        }

        $status = self::DOMAIN_STATUS[$e::class] ?? null;

        if ($status !== null) {
            return $this->error($e->getMessage(), $status);
        }

        $this->logger?->error('Unhandled API exception', ['exception' => $e]);

        return $this->error('An unexpected error occurred.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * @param array<string,string> $headers
     */
    private function error(string $message, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => ['status' => $status, 'message' => $message]], $status, $headers);
    }
}
