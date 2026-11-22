<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use Illuminate\Contracts\Container\Container;
use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;
use Nwidart\Metrics\Http\PrometheusController as MetricsPrometheusController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every metric of nwidart/laravel-metrics, in the Prometheus text exposition format: what the
 * shared store holds, and what is read during the scrape. A store that cannot be read answers 503.
 */
final class PrometheusController
{
    #[ReadOperation]
    public function __invoke(Container $container): Response
    {
        $response = $container->call(MetricsPrometheusController::class);
        assert($response instanceof Response);

        return $response;
    }
}
