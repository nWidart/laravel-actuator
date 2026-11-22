<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Calls an endpoint operation and turns its result into a response.
 */
final class OperationController
{
    public function __invoke(Request $request, Route $route, Container $container): Response
    {
        $operation = $route->getAction('operation');

        if (! is_string($operation)) {
            throw new LogicException("Route [{$route->uri()}] is not an actuator operation.");
        }

        $result = $container->call($operation, $route->parametersWithoutNulls());

        return match (true) {
            $result instanceof Response => $result,
            $result instanceof Responsable => $result->toResponse($request),
            $result !== null => new JsonResponse($result),
            $request->isMethodSafe() => throw new NotFoundHttpException,
            default => new Response(status: Response::HTTP_NO_CONTENT),
        };
    }
}
