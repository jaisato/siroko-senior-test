<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api\Controller\Health;

use Siroko\Cart\Infrastructure\Health\HealthChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /health - the one route declared outside the API Platform resources.
 *
 * It is not part of the API: no read model, no version, and the container
 * healthchecks and load balancers probe it at a fixed path. It sits outside
 * the API prefix, which is also what keeps it public when API_TOKENS is set
 * (the authenticator only guards the versioned routes).
 *
 * 200 when every check passes, 503 otherwise; the body names each check and
 * its verdict, the log has the reason. Never cached: a probe wants the
 * answer of this moment.
 */
#[Route('/health', name: 'health', methods: ['GET'])]
final class HealthController
{
    public function __construct(private readonly HealthChecker $checker) {}

    public function __invoke(): JsonResponse
    {
        $report = $this->checker->check();

        $response = new JsonResponse(
            $report->toArray(),
            $report->isHealthy() ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
