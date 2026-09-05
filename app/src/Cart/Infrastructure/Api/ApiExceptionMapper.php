<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Siroko\Cart\Domain\Exception\CartItemNotFoundException;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\DuplicateProductCodeException;
use Siroko\Cart\Domain\Exception\InvalidCartLineException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Exception\InvalidPriceException;
use Siroko\Cart\Domain\Exception\InvalidProductCodeException;
use Siroko\Cart\Domain\Exception\InvalidQuantityException;
use Siroko\Cart\Domain\Exception\NameInvalidLengthException;
use Siroko\Cart\Domain\Exception\OutOfStockException;
use Siroko\Cart\Domain\Exception\PriceIsNotSameCurrencyException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
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
 * - The status code was 500 for everything. The domain throws its own
 *   exceptions for a cart that does not exist, a rejected quantity or a cart
 *   that is already checked out - all of which reached the client as "the
 *   server broke". A caller could not tell a bad request from an outage, and no
 *   client or monitor could act on the difference.
 * - The message was echoed verbatim. For an unexpected failure that message is
 *   whatever the ORM, the driver or the queue produced: SQL fragments, file
 *   paths, host names, and in the case of a connection failure the DSN itself.
 *
 * Deliberate exceptions carry their own status and a message written to be
 * read. Anything else is a bug, and its message stays in the log.
 *
 * The body follows RFC 7807 (`application/problem+json`): `type`, `title`,
 * `status` and `detail`. API Platform answers the errors it raises itself -
 * unknown route, wrong method, unacceptable format - in the same shape, so a
 * client has one error contract for the whole API.
 */
final class ApiExceptionMapper
{
    public const CONTENT_TYPE = 'application/problem+json';

    /**
     * Domain exceptions the API can report as a rejected request rather than a
     * failure. The domain raises them for input it will not accept, so they are
     * the caller's problem and their messages are safe to return.
     *
     * @var array<class-string<\Throwable>, int>
     */
    private const DOMAIN_STATUS = [
        CartNotFoundException::class => Response::HTTP_NOT_FOUND,
        CartItemNotFoundException::class => Response::HTTP_NOT_FOUND,
        ProductNotFoundException::class => Response::HTTP_NOT_FOUND,
        InvalidCartStatusException::class => Response::HTTP_CONFLICT,
        OutOfStockException::class => Response::HTTP_CONFLICT,
        DuplicateProductCodeException::class => Response::HTTP_CONFLICT,
        InvalidCartLineException::class => Response::HTTP_BAD_REQUEST,
        InvalidIdentifierException::class => Response::HTTP_BAD_REQUEST,
        InvalidPriceException::class => Response::HTTP_BAD_REQUEST,
        InvalidQuantityException::class => Response::HTTP_BAD_REQUEST,
        InvalidProductCodeException::class => Response::HTTP_BAD_REQUEST,
        NameInvalidLengthException::class => Response::HTTP_BAD_REQUEST,
        PriceIsNotSameCurrencyException::class => Response::HTTP_BAD_REQUEST,
    ];

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    public function toResponse(\Throwable $e): JsonResponse
    {
        if ($e instanceof HttpExceptionInterface) {
            return $this->problem($e->getMessage(), $e->getStatusCode(), $e->getHeaders());
        }

        // HttpFoundation's own complaints about the request (a query value the
        // InputBag cannot parse, a malformed header) are the caller's problem;
        // the kernel answers 400 for them and so does the API.
        if ($e instanceof RequestExceptionInterface) {
            return $this->problem('The request is malformed.', Response::HTTP_BAD_REQUEST);
        }

        $status = self::DOMAIN_STATUS[$e::class] ?? null;

        if (null !== $status) {
            return $this->problem($e->getMessage(), $status);
        }

        // The handler checks the code before inserting, but two requests can
        // pass that check at the same time; the unique index then rejects the
        // second insert with a driver exception. That race is still a conflict
        // the caller can understand, not a failure of the service.
        if ($e instanceof UniqueConstraintViolationException) {
            return $this->problem('A resource with the same unique key already exists.', Response::HTTP_CONFLICT);
        }

        $this->logger?->error('Unhandled API exception', ['exception' => $e]);

        return $this->problem('An unexpected error occurred.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * @param array<string, string> $headers
     */
    private function problem(string $detail, int $status, array $headers = []): JsonResponse
    {
        $body = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
        ];

        return new JsonResponse($body, $status, $headers + ['Content-Type' => self::CONTENT_TYPE]);
    }
}
