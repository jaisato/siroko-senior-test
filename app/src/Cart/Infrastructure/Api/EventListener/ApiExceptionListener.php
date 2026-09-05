<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\EventListener;

use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders the errors that never reach a controller - unknown route, wrong
 * method, unacceptable format - through the same mapper the controllers use.
 *
 * Without it, those errors were negotiated by API Platform against the
 * client's Accept header: a client asking for `application/json` got an RFC
 * 7807 body labelled `application/json`, while every controller error came as
 * `application/problem+json`. One media type for every error of the API is
 * what lets a client handle them with a single code path.
 *
 * Only the versioned API surface is covered (`{prefix}/v1/...`); the
 * documentation pages keep their own rendering. In debug mode, exceptions that
 * are not deliberate HTTP errors are left to Symfony, so a developer still gets
 * the exception page with the stack trace.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final class ApiExceptionListener
{
    public function __construct(
        private readonly ApiExceptionMapper $mapper,
        private readonly string $apiPrefix,
        private readonly bool $debug,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        if (!str_starts_with($path, rtrim($this->apiPrefix, '/') . '/v1/')) {
            return;
        }

        $throwable = $event->getThrowable();

        if ($this->debug && !$throwable instanceof HttpExceptionInterface) {
            return;
        }

        $event->setResponse($this->mapper->toResponse($throwable));
        $event->stopPropagation();
    }
}
