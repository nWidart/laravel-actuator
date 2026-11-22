<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use Illuminate\Http\Response;
use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;
use Nwidart\Actuator\Metrics\Export\PrometheusTextFormat;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Throwable;

/**
 * Every meter, in the Prometheus text exposition format.
 */
final class PrometheusController
{
    #[ReadOperation]
    public function __invoke(MeterRegistry $registry, PrometheusTextFormat $format): Response
    {
        try {
            $meters = $registry->collect();
        } catch (Throwable $e) {
            report($e);

            // A failed scrape tells Prometheus the target is down. Partial numbers would not.
            return new Response("# The metrics store is not available.\n", 503, ['Content-Type' => PrometheusTextFormat::CONTENT_TYPE]);
        }

        return new Response($format->render($meters), 200, ['Content-Type' => PrometheusTextFormat::CONTENT_TYPE]);
    }
}
