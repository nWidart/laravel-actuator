<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Nwidart\Actuator\Health\HealthEndpoint;

final class HealthController
{
    public function __invoke(HealthEndpoint $endpoint, ?string $path = null): JsonResponse
    {
        $segments = $path === null ? [] : array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        $health = $endpoint->health($segments);

        abort_if($health === null, 404);

        return new JsonResponse($health, $health['status']->httpStatus());
    }
}
