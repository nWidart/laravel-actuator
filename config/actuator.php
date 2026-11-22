<?php

use Nwidart\Actuator\Health\Indicators\CacheHealthIndicator;
use Nwidart\Actuator\Health\Indicators\DatabaseHealthIndicator;
use Nwidart\Actuator\Health\Indicators\DiskSpaceHealthIndicator;
use Nwidart\Actuator\Health\Indicators\PingHealthIndicator;
use Nwidart\Actuator\Health\Indicators\ReadinessStateHealthIndicator;
use Nwidart\Actuator\Info\Contributors\AppInfoContributor;
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
            'db' => DatabaseHealthIndicator::class,
            'cache' => CacheHealthIndicator::class,
            // 'redis' => Nwidart\Actuator\Health\Indicators\RedisHealthIndicator::class,
        ],

        // Groups are reachable at /{base_path}/health/{group}. Each group
        // may override "show_details" and "show_components".
        'groups' => [
            'liveness' => ['include' => ['ping']],
            'readiness' => ['include' => ['readinessState']],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Info endpoint
    |--------------------------------------------------------------------------
    */

    'info' => [
        'middleware' => [],

        'contributors' => [
            'app' => AppInfoContributor::class,
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
    | Metrics are collected while "enabled" is true and the "prometheus"
    | endpoint is exposed. PHP processes share no memory, so they are
    | kept in a store:
    |
    | "cache"  one entry in a Laravel cache store, written under a lock.
    |          No setup, for development and small applications.
    | "redis"  one hash on a Laravel Redis connection. For production.
    |
    | Any other value is the class name of your own MetricsStore.
    |
    */

    'prometheus' => [
        'middleware' => [],
    ],

    'metrics' => [
        'enabled' => env('ACTUATOR_METRICS_ENABLED', true),

        'store' => env('ACTUATOR_METRICS_STORE', 'cache'),

        // The cache store to use. Null is the default cache store.
        'cache' => ['store' => env('ACTUATOR_METRICS_CACHE_STORE')],

        'redis' => ['connection' => env('ACTUATOR_METRICS_REDIS_CONNECTION', 'default')],

        // Tags added to every meter, e.g. ['application' => 'shop'].
        'tags' => [],

        // Turn meters off by name prefix, e.g. ['db.queries' => false,
        // 'php' => false]. The longest prefix wins.
        'enable' => [],

        // Histogram buckets of timers and summaries, as upper bounds in
        // the base unit (seconds for timers). Remove a meter, or give it
        // an empty list, to keep only its count and sum.
        'distribution' => [
            'slo' => [
                'http.server.requests' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],
                'db.queries' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 5],
                'queue.jobs' => [0.01, 0.05, 0.1, 0.5, 1, 5, 10, 30, 60, 300],
                'tasks.scheduled.execution' => [0.1, 0.5, 1, 5, 10, 30, 60, 300, 900],
            ],
        ],
    ],

];
