<?php

declare(strict_types=1);

namespace Nwidart\Actuator;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nwidart\Actuator\Console\ClearMetricsCommand;
use Nwidart\Actuator\Endpoints\EndpointRegistry;
use Nwidart\Actuator\Metrics\Binders\DatabaseQueryMetrics;
use Nwidart\Actuator\Metrics\Binders\ExceptionMetrics;
use Nwidart\Actuator\Metrics\Binders\HttpServerRequestMetrics;
use Nwidart\Actuator\Metrics\Binders\PhpMetrics;
use Nwidart\Actuator\Metrics\Binders\QueueJobMetrics;
use Nwidart\Actuator\Metrics\Binders\ScheduledTaskMetrics;
use Nwidart\Actuator\Metrics\Binders\SystemMetrics;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Actuator\Metrics\MetricsStore;
use Nwidart\Actuator\Metrics\Stores\CacheStore;
use Nwidart\Actuator\Metrics\Stores\RedisStore;

final class ActuatorServiceProvider extends ServiceProvider
{
    private const METER_BINDERS = [
        HttpServerRequestMetrics::class,
        QueueJobMetrics::class,
        DatabaseQueryMetrics::class,
        ExceptionMetrics::class,
        ScheduledTaskMetrics::class,
        PhpMetrics::class,
        SystemMetrics::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/actuator.php', 'actuator');

        $this->app->singletonIf(EndpointRegistry::class);

        $this->app->singleton(MetricsStore::class, static function (Application $app): MetricsStore {
            $store = config()->string('actuator.metrics.store', 'cache');

            if ($store === 'cache') {
                $name = config('actuator.metrics.cache.store');

                return new CacheStore($app->make(CacheFactory::class)->store(is_string($name) ? $name : null));
            }

            if ($store === 'redis') {
                return new RedisStore($app->make(RedisManager::class)->connection(config()->string('actuator.metrics.redis.connection', 'default')));
            }

            $custom = $app->make($store);

            return $custom instanceof MetricsStore ? $custom : throw new InvalidArgumentException("Metrics store [{$store}] must implement ".MetricsStore::class.'.');
        });

        $this->app->singleton(MeterRegistry::class, function (Application $app): MeterRegistry {
            /** @var array<string, string|int|float|bool> $tags */
            $tags = config()->array('actuator.metrics.tags', []);

            /** @var array<string, bool> $enable */
            $enable = config()->array('actuator.metrics.enable', []);

            /** @var array<string, list<int|float>> $buckets */
            $buckets = config()->array('actuator.metrics.distribution.slo', []);

            return new MeterRegistry(
                resolveStore: static fn (): MetricsStore => $app->make(MetricsStore::class),
                active: $this->collectsMetrics(),
                commonTags: $tags,
                enable: $enable,
                buckets: $buckets,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/actuator.php' => config_path('actuator.php'),
            ], 'actuator-config');

            $this->commands([ClearMetricsCommand::class]);
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

        $this->collectMetrics();
    }

    /**
     * Metrics cost a store write per request, so they are only collected
     * when something reads them. Prometheus is the only reader for now.
     */
    private function collectsMetrics(): bool
    {
        return config()->boolean('actuator.metrics.enabled', true)
            && isset($this->app->make(EndpointRegistry::class)->exposed()['prometheus']);
    }

    private function collectMetrics(): void
    {
        // The registry is not even built when nothing is collected.
        if (! $this->collectsMetrics()) {
            return;
        }

        $registry = $this->app->make(MeterRegistry::class);

        foreach (self::METER_BINDERS as $binder) {
            $this->app->make($binder)->bind($registry);
        }

        // Runs after the response is sent, and at the end of a command.
        $this->app->terminating(static fn () => $registry->flush());
    }
}
