<?php

use Nwidart\Actuator\Health\Indicators\CacheHealthIndicator;
use Nwidart\Actuator\Health\Indicators\DatabaseHealthIndicator;
use Nwidart\Actuator\Health\Indicators\DiskSpaceHealthIndicator;
use Nwidart\Actuator\Health\Indicators\MigrationsHealthIndicator;
use Nwidart\Actuator\Health\Indicators\PingHealthIndicator;
use Nwidart\Actuator\Health\Indicators\ReadinessStateHealthIndicator;
use Nwidart\Actuator\Info\Contributors\AppInfoContributor;
use Nwidart\Actuator\Info\Contributors\BuildInfoContributor;
use Nwidart\Actuator\Info\Contributors\GitInfoContributor;
use Nwidart\Actuator\Info\Contributors\OsInfoContributor;
use Nwidart\Actuator\Info\Contributors\PhpInfoContributor;
use Nwidart\Actuator\Info\Contributors\ProcessInfoContributor;

return [

    /*
    |--------------------------------------------------------------------------
    | Base path
    |--------------------------------------------------------------------------
    |
    | The URI prefix under which every actuator endpoint is registered.
    | The discovery page listing the exposed endpoints lives at this path.
    |
    */

    'base_path' => env('ACTUATOR_BASE_PATH', 'actuator'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to every actuator route. Each endpoint below can
    | add its own middleware, e.g. to keep "health" public for probes
    | while protecting "env" behind authentication. A custom endpoint
    | reads its middleware from a key named after its id:
    | 'modules' => ['middleware' => ['auth']].
    |
    */

    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Exposure
    |--------------------------------------------------------------------------
    |
    | Endpoints reachable over HTTP. Use '*' to expose all of them. An
    | excluded endpoint is never exposed, even when it is included.
    | Available: health, info, env, mappings, scheduledtasks, prometheus,
    | and each custom endpoint registered with Actuator::endpoint().
    |
    */

    'exposure' => [
        'include' => ['health'],
        'exclude' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health endpoint
    |--------------------------------------------------------------------------
    |
    | "show_details" and "show_components" accept "never", "always" or
    | "when_authorized". "when_authorized" grants access when the
    | "viewActuator" gate allows it. A null "show_components"
    | follows "show_details".
    |
    | Indicators and info contributors are a class name, or a class
    | name with constructor arguments: [Indicator::class, ['arg' => 1]].
    |
    */

    'health' => [
        'middleware' => [],
        'show_details' => env('ACTUATOR_HEALTH_SHOW_DETAILS', 'never'),
        'show_components' => null,

        'indicators' => [
            'ping' => PingHealthIndicator::class,
            'readinessState' => ReadinessStateHealthIndicator::class,
            'diskSpace' => DiskSpaceHealthIndicator::class,
            // DOWN near the server's connection limit, e.g. at 90%:
            // [DatabaseHealthIndicator::class, ['maxConnectionUsage' => 0.9]]
            'db' => DatabaseHealthIndicator::class,
            // OUT_OF_SERVICE while migrations have not run.
            'migrations' => MigrationsHealthIndicator::class,
            'cache' => CacheHealthIndicator::class,
            // 'redis' => Nwidart\Actuator\Health\Indicators\RedisHealthIndicator::class,
        ],

        // Groups are reachable at /{base_path}/health/{group}. Each group
        // may override "show_details" and "show_components".
        'groups' => [
            'liveness' => ['include' => ['ping']],
            'readiness' => ['include' => ['readinessState', 'migrations']],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Info endpoint
    |--------------------------------------------------------------------------
    |
    | "php artisan actuator:build-info" writes the build info file at build
    | time. The "build" contributor shows it, and the "git" contributor
    | reads its commit and branch when the .git directory is absent.
    |
    */

    'info' => [
        'middleware' => [],

        'build' => [
            'path' => base_path('bootstrap/cache/actuator-build.json'),
        ],

        'contributors' => [
            'app' => AppInfoContributor::class,
            'build' => BuildInfoContributor::class,
            'git' => GitInfoContributor::class,
            'php' => PhpInfoContributor::class,
            'os' => OsInfoContributor::class,
            'process' => ProcessInfoContributor::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Env endpoint
    |--------------------------------------------------------------------------
    |
    | Lists configuration and environment variables. Values are masked
    | unless "show_values" allows them: "never", "always" or
    | "when_authorized".
    |
    */

    'env' => [
        'middleware' => [],
        'show_values' => 'never',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mappings and scheduled tasks endpoints
    |--------------------------------------------------------------------------
    */

    'mappings' => [
        'middleware' => [],
    ],

    'scheduledtasks' => [
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus endpoint and metrics
    |--------------------------------------------------------------------------
    |
    | Metrics are recorded, stored and served by nwidart/laravel-metrics:
    | the store (Redis, or a cache store for development), the built-in
    | instrumentation and the labels added to every sample are set in
    | config/metrics.php. The "prometheus" endpoint serves them at
    | /actuator/prometheus. These settings are for the meters you create
    | with MeterRegistry.
    |
    */

    'prometheus' => [
        'middleware' => [],
    ],

    'metrics' => [
        // Turn your meters off by name prefix, e.g. ['orders' => false,
        // 'orders.placed' => true]. The longest prefix wins.
        'enable' => [],

        // Histogram buckets of your timers and summaries, as upper bounds
        // in the base unit (seconds for timers), e.g.
        // ['payment.charge' => [0.1, 0.25, 0.5, 1, 2.5, 5]]. Without bounds,
        // a timer or summary keeps only its count and sum.
        'distribution' => [
            'slo' => [],
        ],
    ],

];
