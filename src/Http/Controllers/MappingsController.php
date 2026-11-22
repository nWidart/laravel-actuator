<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

final class MappingsController
{
    public function __invoke(Router $router): JsonResponse
    {
        $routes = array_map(static fn (Route $route): array => [
            'methods' => $route->methods(),
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'action' => $route->getActionName(),
            'middleware' => array_values(array_map(
                static fn (mixed $middleware): string => is_string($middleware) ? $middleware : get_debug_type($middleware),
                $route->gatherMiddleware(),
            )),
            'domain' => $route->getDomain(),
        ], $router->getRoutes()->getRoutes());

        return new JsonResponse(['routes' => $routes]);
    }
}
