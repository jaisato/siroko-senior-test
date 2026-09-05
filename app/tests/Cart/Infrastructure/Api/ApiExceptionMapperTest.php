<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api;

use Doctrine\DBAL\Driver\PDO\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
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
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ApiExceptionMapperTest extends TestCase
{
    #[DataProvider('domainExceptions')]
    public function test_domain_exceptions_map_to_their_status_and_keep_their_message(\Throwable $exception, int $status): void
    {
        $response = (new ApiExceptionMapper())->toResponse($exception);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $body = self::decode($response->getContent());
        self::assertSame('about:blank', $body['type']);
        self::assertSame($status, $body['status']);
        self::assertSame($exception->getMessage(), $body['detail']);
        self::assertIsString($body['title']);
        self::assertNotSame('', $body['title']);
    }

    /**
     * @return iterable<string, array{\Throwable, int}>
     */
    public static function domainExceptions(): iterable
    {
        $cartId = CartId::fromString(Uuid::uuid4()->toString());
        $productId = ProductId::fromString(Uuid::uuid4()->toString());

        yield 'cart not found' => [CartNotFoundException::withId($cartId), 404];
        yield 'item not found' => [CartItemNotFoundException::inCart(ItemId::fromString(Uuid::uuid4()->toString()), $cartId), 404];
        yield 'product not found' => [ProductNotFoundException::withId($productId), 404];
        yield 'cart not pending' => [new InvalidCartStatusException('Cart is not pending'), 409];
        yield 'out of stock' => [new OutOfStockException('Product is out of stock'), 409];
        yield 'duplicate code' => [DuplicateProductCodeException::forCode(ProductCode::fromString('X')), 409];
        yield 'bad line' => [InvalidCartLineException::notAnObject(0), 400];
        yield 'bad id' => [InvalidIdentifierException::forType(CartId::class), 400];
        yield 'bad price' => [InvalidPriceException::negative(), 400];
        yield 'bad quantity' => [new InvalidQuantityException('Quantity must be an integer.'), 400];
        yield 'bad code' => [new InvalidProductCodeException('too long'), 400];
        yield 'bad name' => [new NameInvalidLengthException('too short'), 400];
        yield 'currency mismatch' => [new PriceIsNotSameCurrencyException('mismatch'), 400];
    }

    public function test_http_exceptions_keep_their_status_message_and_headers(): void
    {
        $response = (new ApiExceptionMapper())->toResponse(new TooManyRequestsHttpException(30, 'Slow down'));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertSame('Slow down', self::decode($response->getContent())['detail']);
        self::assertSame('Too Many Requests', self::decode($response->getContent())['title']);
    }

    public function test_a_bad_request_exception_is_a_400_problem(): void
    {
        $response = (new ApiExceptionMapper())->toResponse(new BadRequestHttpException('A JSON body is required.'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('A JSON body is required.', self::decode($response->getContent())['detail']);
    }

    /** The unique index settles the race the handler's check cannot. */
    public function test_a_unique_constraint_violation_is_a_409_problem_without_the_driver_message(): void
    {
        $driver = new DriverException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', '23000', 1062);
        $exception = new UniqueConstraintViolationException($driver, null);

        $response = (new ApiExceptionMapper())->toResponse($exception);

        self::assertSame(409, $response->getStatusCode());
        $detail = self::decode($response->getContent())['detail'];
        self::assertIsString($detail);
        self::assertStringNotContainsString('SQLSTATE', $detail);
        self::assertStringContainsString('already exists', $detail);
    }

    /**
     * Anything else is a bug: the client gets a generic 500 and the real
     * message - which may contain a DSN, SQL or file paths - goes to the log.
     */
    public function test_an_unexpected_exception_is_a_generic_500_that_is_logged(): void
    {
        $logger = new RecordingLogger();

        $secret = new \RuntimeException('could not connect to mysql://siroko:hunter2@db/siroko_cart');

        $response = (new ApiExceptionMapper($logger))->toResponse($secret);

        self::assertSame(500, $response->getStatusCode());
        $body = self::decode($response->getContent());
        self::assertSame('An unexpected error occurred.', $body['detail']);
        self::assertSame('Internal Server Error', $body['title']);
        self::assertStringNotContainsString('hunter2', (string) $response->getContent());

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame($secret, $logger->records[0]['context']['exception']);
    }

    public function test_it_works_without_a_logger(): void
    {
        $response = (new ApiExceptionMapper())->toResponse(new \LogicException('boom'));

        self::assertSame(500, $response->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string|false $content): array
    {
        self::assertIsString($content);
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}
