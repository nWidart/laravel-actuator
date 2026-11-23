<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;
use Nwidart\Actuator\Endpoints\Attributes\Selector;
use Nwidart\Actuator\Health\HealthEndpoint;

final class HealthController
{
    #[ReadOperation]
    public function health(HealthEndpoint $endpoint): ?JsonResponse
    {
        return $this->respond($endpoint, []);
    }

    /**
     * The health of a group, a component, or a component of a group.
     */
    #[ReadOperation]
    public function healthForPath(HealthEndpoint $endpoint, #[Selector(remaining: true)] string $path): ?JsonResponse
    {
        return $this->respond($endpoint, array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '')));
    }

    /**
     * @param  list<string>  $segments
     */
    private function respond(HealthEndpoint $endpoint, array $segments): ?JsonResponse
    {
        $health = $endpoint->health($segments);

        return $health === null ? null : new JsonResponse($health, $endpoint->httpStatus($segments, $health['status']));
    }
}
