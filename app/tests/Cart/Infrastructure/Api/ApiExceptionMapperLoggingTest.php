<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The wiring rather than the class: the mapper the controllers receive
 * writes through Monolog, and the record carries the exception. In the test
 * environment Monolog keeps its records in memory (handler type `test`),
 * which is what makes them readable here.
 */
final class ApiExceptionMapperLoggingTest extends KernelTestCase
{
    public function test_an_unexpected_exception_is_logged_through_monolog_with_the_exception_attached(): void
    {
        self::bootKernel();
        $mapper = static::getContainer()->get(ApiExceptionMapper::class);
        $handler = self::inMemoryHandlerOf(static::getContainer()->get(LoggerInterface::class));

        $failure = new \RuntimeException('could not connect to mysql://siroko:hunter2@db/siroko_cart');

        $response = $mapper->toResponse($failure);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('hunter2', (string) $response->getContent());

        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn(LogRecord $record): bool => 'Unhandled API exception' === $record->message,
        ));
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]->level->toPsrLogLevel());
        self::assertSame($failure, $records[0]->context['exception'] ?? null);
    }

    /**
     * The `logger` service is Monolog's `app` channel; in the test environment
     * its one handler keeps the records instead of writing them.
     */
    private static function inMemoryHandlerOf(object $logger): TestHandler
    {
        self::assertInstanceOf(Logger::class, $logger);

        $inMemory = null;

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                $inMemory = $handler;
            }
        }

        self::assertInstanceOf(TestHandler::class, $inMemory, 'the test environment keeps its log in memory');

        return $inMemory;
    }
}
