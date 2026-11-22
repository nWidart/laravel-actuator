<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics\Binders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Route;
use Nwidart\Actuator\Metrics\MeterBinder;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Throwable;

/**
 * Times every HTTP request, as Spring's "http.server.requests" does.
 * Tag contributors receive the RequestHandled event.
 *
 * @internal
 */
final readonly class HttpServerRequestMetrics implements MeterBinder
{
    public const NAME = 'http.server.requests';

    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public function __construct(private Dispatcher $events) {}

    public function bind(MeterRegistry $registry): void
    {
        $registry->describe(self::NAME, 'Duration of HTTP server request handling.');

        if (! $registry->enabled(self::NAME)) {
            return;
        }

        $this->events->listen(RequestHandled::class, function (RequestHandled $event) use ($registry): void {
            $route = $event->request->route();

            // Scrapes and probes would otherwise dominate the numbers.
            if ($route instanceof Route && str_starts_with((string) $route->getName(), 'actuator.')) {
                return;
            }

            $status = $event->response->getStatusCode();
            $exception = $event->response->exception ?? null;
            $start = $event->request->server('REQUEST_TIME_FLOAT');

            $registry->timer(self::NAME, [
                'method' => in_array($event->request->getMethod(), self::METHODS, true) ? $event->request->getMethod() : 'OTHER',
                'uri' => $this->uri($route, $status),
                'status' => $status,
                'outcome' => match (intdiv($status, 100)) {
                    1 => 'INFORMATIONAL',
                    2 => 'SUCCESS',
                    3 => 'REDIRECTION',
                    4 => 'CLIENT_ERROR',
                    5 => 'SERVER_ERROR',
                    default => 'UNKNOWN',
                },
                'exception' => $exception instanceof Throwable ? ExceptionMetrics::name($exception) : 'none',
                ...$registry->tagsFor(self::NAME, $event),
            ])->record(is_numeric($start) ? microtime(true) - (float) $start : 0.0);
        });
    }

    /**
     * The route pattern, never the raw path: a path with ids in it would
     * create one series per id.
     */
    private function uri(mixed $route, int $status): string
    {
        return match (true) {
            $route instanceof Route => '/'.ltrim($route->uri(), '/'),
            $status === 404 => 'NOT_FOUND',
            $status >= 300 && $status < 400 => 'REDIRECTION',
            default => 'UNKNOWN',
        };
    }
}
