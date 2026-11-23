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
            // Size of each queue of "queue.queues" below, WARNING or DOWN from a threshold:
            // 'queue' => [Nwidart\Actuator\Health\Indicators\QueueHealthIndicator::class, ['warningThreshold' => 100, 'downThreshold' => 1000]],
            // Failed jobs, WARNING from 1, optionally within the last minutes:
            // 'failedJobs' => [Nwidart\Actuator\Health\Indicators\FailedJobsHealthIndicator::class, ['downThreshold' => 50, 'minutes' => 60]],
            // Horizon status, DOWN when inactive, WARNING when paused:
            // 'horizon' => Nwidart\Actuator\Health\Indicators\HorizonHealthIndicator::class,
            // DOWN when "schedule:run" has not started for 5 minutes, WARNING
            // after 2. Reads the store set in "scheduler" below:
            // 'scheduler' => Nwidart\Actuator\Health\Indicators\SchedulerHealthIndicator::class,
        ],

        // Severity order, most severe first, and the HTTP status code of
        // each status. A status missing from "order" sorts after the
        // listed ones. An unmapped status keeps its default code: 503
        // for DOWN and OUT_OF_SERVICE, 200 otherwise.
        'status' => [
            // 'order' => ['DOWN', 'OUT_OF_SERVICE', 'WARNING', 'UP', 'UNKNOWN'],
            // 'http_mapping' => ['DOWN' => 503, 'OUT_OF_SERVICE' => 503],
        ],

        // Groups are reachable at /{base_path}/health/{group}. Each group
        // may override "show_details", "show_components" and "status":
        // 'status' => ['order' => [...], 'http_mapping' => [...]].
        'groups' => [
            'liveness' => ['include' => ['ping']],
            'readiness' => ['include' => ['readinessState', 'migrations']],
        ],

        // Reuse each indicator's result for "ttl" seconds (0 turns the
        // cache off), so frequent probes do not run every check. 2 to 5
        // seconds is enough. The store must be local to the instance:
        // "apc" under PHP-FPM, "file" when storage is local to the
        // container, "array" under Octane. A shared store, such as redis
        // or database, would hide one instance's failure behind another's
        // success.
        'cache' => [
            'ttl' => env('ACTUATOR_HEALTH_CACHE_TTL', 0),
            'store' => env('ACTUATOR_HEALTH_CACHE_STORE', 'file'),
        ],

        // Export the health status as the gauges laravel_health_status and
        // laravel_health_overall_status of the prometheus endpoint. Each
        // scrape then runs every indicator unless the cache above holds its
        // result: a hanging dependency can make the scrape time out. Turn
        // the cache on and keep connect timeouts short.
        'metrics' => env('ACTUATOR_HEALTH_METRICS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | The queues the "queue" health indicator watches, by connection:
    | ['redis' => ['default', 'emails']]. Empty watches the default
    | queue of the default connection.
    |
    */

    'queue' => [
        'queues' => [],
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

    // The message of a task's last exception is shown when "show_exceptions"
    // allows it: "never", "always" or "when_authorized". Its type is always
    // shown. The message can hold a command's arguments.
    'scheduledtasks' => [
        'middleware' => [],
        'show_exceptions' => 'never',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    |
    | The scheduler records the last execution of each task and the time of
    | each "schedule:run" in a cache store. The "scheduledtasks" endpoint
    | and the "scheduler" health indicator read them. The store must be
    | shared by the scheduler and the web instances, such as redis or
    | database: "file" and "array" do not work when the scheduler runs on
    | another host. Null is the application's default cache store.
    |
    */

    'scheduler' => [
        'record' => env('ACTUATOR_SCHEDULER_RECORD', true),
        'store' => env('ACTUATOR_SCHEDULER_STORE'),
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
