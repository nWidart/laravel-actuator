<?php

declare(strict_types=1);

namespace Nwidart\Actuator;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\ServiceProvider;
use Nwidart\Actuator\Console\BuildInfoCommand;
use Nwidart\Actuator\Console\ClearMetricsCommand;
use Nwidart\Actuator\Console\HealthCommand;
use Nwidart\Actuator\Endpoints\EndpointRegistry;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Metrics\AttributeContributors;
use Nwidart\Metrics\Metrics;
use Nwidart\Metrics\MetricsServiceProvider;
use OpenTelemetry\SDK\Metrics\Data\Metric;

final class ActuatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/actuator.php', 'actuator');

        $this->app->singletonIf(EndpointRegistry::class);

        // Metrics are recorded, stored and served by nwidart/laravel-metrics. It is registered here
        // too, for applications that turn package discovery off.
        $this->app->register(MetricsServiceProvider::class);

        // Health probes and scrapes would otherwise drown the application's own requests.
        config()->set('metrics.ignore_routes', [...config()->array('metrics.ignore_routes', []), 'actuator.*']);

        $this->app->singleton(MeterRegistry::class, static function (Application $app): MeterRegistry {
            /** @var array<string, bool> $enable */
            $enable = config()->array('actuator.metrics.enable', []);

            /** @var array<string, list<int|float>> $buckets */
            $buckets = config()->array('actuator.metrics.distribution.slo', []);

            return new MeterRegistry($app->make(Metrics::class), $app->make(AttributeContributors::class), $enable, $buckets);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/actuator.php' => config_path('actuator.php'),
            ], 'actuator-config');

            $this->commands([BuildInfoCommand::class, ClearMetricsCommand::class, HealthCommand::class]);
        }

        $this->app->make(EndpointRegistry::class)->loadRoutes();

        // Actuator endpoints keep answering in maintenance mode, so the
        // readiness probe reports OUT_OF_SERVICE instead of an HTML 503.
        // An empty base path is skipped: excepting "/*" would disable
        // maintenance mode for the whole application.
        $basePath = trim(config()->string('actuator.base_path'), '/');

        if ($basePath !== '') {
            PreventRequestsDuringMaintenance::except([$basePath, $basePath.'/*']);
        }

        // Applications register callback gauges and tag contributors when the registry is resolved
        // (callAfterResolving). Build it on every request, so that they exist in the scrape and in
        // the requests they tag. It holds no meter until one is used.
        $registry = $this->app->make(MeterRegistry::class);

        // A meter turned off by an "enable" prefix also stops serving the series it stored before.
        if (config()->array('actuator.metrics.enable', []) !== []) {
            $this->app->make(Metrics::class)->exclude(static fn (Metric $metric): bool => $metric->instrumentationScope->getName() === MeterRegistry::SCOPE
                && ! $registry->enabled($metric->name));
        }
    }
}
