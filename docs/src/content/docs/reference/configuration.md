---
title: Configuration
description: Every key of config/actuator.php, with its type, default value and environment variable.
---

The configuration lives in `config/actuator.php`. Publish it with:

```bash
php artisan vendor:publish --tag=actuator-config
```

Keys you do not publish keep their default values.

## Summary

| Key                              | Type                    | Default                                  | Environment variable           |
| -------------------------------- | ----------------------- | ---------------------------------------- | ------------------------------ |
| `base_path`                      | string                  | `'actuator'`                             | `ACTUATOR_BASE_PATH`           |
| `middleware`                     | list of strings         | `[]`                                     |                                |
| `exposure.include`               | list of endpoint IDs    | `['health']`                             |                                |
| `exposure.exclude`               | list of endpoint IDs    | `[]`                                     |                                |
| `health.middleware`              | list of strings         | `[]`                                     |                                |
| `health.show_details`            | visibility              | `'never'`                                | `ACTUATOR_HEALTH_SHOW_DETAILS` |
| `health.show_components`         | visibility or `null`    | `null`                                   |                                |
| `health.indicators`              | map of definitions      | `ping`, `readinessState`, `diskSpace`, `db`, `migrations`, `cache` |      |
| `health.status.order`            | list of status codes    | `DOWN`, `OUT_OF_SERVICE`, `WARNING`, `UP`, `UNKNOWN` |                    |
| `health.status.http_mapping`     | map of status to int    | `[]`                                     |                                |
| `health.groups`                  | map of groups           | `liveness`, `readiness`                  |                                |
| `health.cache.ttl`               | int (seconds)           | `0`                                      | `ACTUATOR_HEALTH_CACHE_TTL`    |
| `health.cache.store`             | string                  | `'file'`                                 | `ACTUATOR_HEALTH_CACHE_STORE`  |
| `health.metrics`                 | bool                    | `false`                                  | `ACTUATOR_HEALTH_METRICS`      |
| `queue.queues`                   | map of lists of strings | `[]`                                     |                                |
| `queue.metrics`                  | bool                    | `false`                                  | `ACTUATOR_QUEUE_METRICS`       |
| `info.middleware`                | list of strings         | `[]`                                     |                                |
| `info.build.path`                | string                  | `base_path('bootstrap/cache/actuator-build.json')` |                      |
| `info.contributors`              | map of definitions      | `app`, `build`, `git`, `php`, `os`, `process` |                           |
| `env.middleware`                 | list of strings         | `[]`                                     |                                |
| `env.show_values`                | visibility              | `'never'`                                |                                |
| `mappings.middleware`            | list of strings         | `[]`                                     |                                |
| `scheduledtasks.middleware`      | list of strings         | `[]`                                     |                                |
| `scheduledtasks.show_exceptions` | visibility              | `'never'`                                |                                |
| `scheduler.record`               | bool                    | `true`                                   | `ACTUATOR_SCHEDULER_RECORD`    |
| `scheduler.store`                | string or `null`        | `null`                                   | `ACTUATOR_SCHEDULER_STORE`     |
| `prometheus.middleware`          | list of strings         | `[]`                                     |                                |
| `metrics.enable`                 | map of booleans         | `[]`                                     |                                |
| `metrics.distribution.slo`       | map of lists of numbers | `[]`                                     |                                |
| `{id}.middleware`                | list of strings         | `[]`                                     |                                |

Keys read when routes are registered: `base_path`, `exposure.*` and every `middleware` key. After a
change, rebuild the route cache if you use one. The `metrics.*` keys are read once, when the
application starts: restart queue workers and Octane after a change. The other keys are read on
each request.

The store, the built-in metrics and the labels of every sample are set in `config/metrics.php`,
the file of [nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics#configuration). See
[the metrics model](/laravel-actuator/concepts/metrics-model/#two-packages).

## `base_path`

URI prefix of every actuator route. The [discovery endpoint](/laravel-actuator/reference/endpoints/discovery/)
answers at this path.

```php
'base_path' => env('ACTUATOR_BASE_PATH', 'actuator'),
```

Requests under this prefix bypass maintenance mode. An empty string puts the endpoints at the root
of the application and disables the maintenance mode bypass.

## `middleware`

Middleware applied to every actuator route, including the discovery endpoint.

```php
'middleware' => [],
```

Each entry must be a string: a middleware alias (`auth`, `can:viewActuator`), a middleware group
(`web`) or a class name. Any other value throws `InvalidArgumentException`.

## `exposure`

Endpoints that get a route.

```php
'exposure' => [
    'include' => ['health'],
    'exclude' => [],
],
```

| Key       | Description                                                              |
| --------- | ------------------------------------------------------------------------ |
| `include` | Endpoint IDs to expose, or `'*'` for all.                                |
| `exclude` | Endpoint IDs never to expose, or `'*'` for all. Wins over `include`.     |

Endpoint IDs: `health`, `info`, `env`, `mappings`, `scheduledtasks`, `prometheus`, and the ID of each
[custom endpoint](/laravel-actuator/reference/custom-endpoints/). `'*'` includes custom endpoints.
Unknown IDs are ignored. The discovery endpoint is always registered.

## `health`

```php
'health' => [
    'middleware' => [],
    'show_details' => env('ACTUATOR_HEALTH_SHOW_DETAILS', 'never'),
    'show_components' => null,
    'indicators' => [/* ... */],
    'status' => [/* ... */],
    'groups' => [/* ... */],
    'cache' => [/* ... */],
],
```

### `health.middleware`

Middleware added after the shared `middleware` on the health routes. Same format as
[`middleware`](#middleware).

### `health.show_details`

[Visibility](#visibility-values) of each component's `details`.

### `health.show_components`

[Visibility](#visibility-values) of the `components` list, and of the per-component paths. When
`null`, it takes the value of `show_details`.

Components can be visible without details. Details are never visible without components, because
they appear inside components.

### `health.indicators`

Map of component name to indicator [definition](#definitions). The class must implement
`Nwidart\Actuator\Health\HealthIndicator`.

```php
'indicators' => [
    'ping' => PingHealthIndicator::class,
    'readinessState' => ReadinessStateHealthIndicator::class,
    'diskSpace' => DiskSpaceHealthIndicator::class,
    'db' => DatabaseHealthIndicator::class,
    'migrations' => MigrationsHealthIndicator::class,
    'cache' => CacheHealthIndicator::class,
    // 'redis' => RedisHealthIndicator::class,
],
```

The order of the map is the order of the components in the response. See the
[health indicators reference](/laravel-actuator/reference/health-indicators/) for each built-in
indicator.

### `health.status`

How statuses aggregate and which HTTP status code each one returns.

```php
'status' => [
    'order' => ['DOWN', 'OUT_OF_SERVICE', 'WARNING', 'UP', 'UNKNOWN'],
    'http_mapping' => ['WARNING' => 207, 'DOWN' => 500],
],
```

| Key            | Type                 | Description                                                                 |
| -------------- | -------------------- | --------------------------------------------------------------------------- |
| `order`        | list of status codes | Severity order, most severe first. A status missing from the list sorts after the listed ones, in default order. |
| `http_mapping` | map of status to int | HTTP status code for each status. An unmapped status keeps its default: 503 for `DOWN` and `OUT_OF_SERVICE`, 200 otherwise. |

Both keys are commented out by default. Status codes are case-insensitive. These throw
`InvalidArgumentException` on the next request, with a message that names the config key:

- An unknown status code.
- A status code listed twice, in any case, such as `'down'` and `'DOWN'`.
- An `order` that is not a list.
- An `http_mapping` that is not an array.
- An HTTP status code that is not an integer from 200 to 599.

### `health.groups`

Map of group name to group settings.

```php
'groups' => [
    'liveness' => ['include' => ['ping']],
    'readiness' => ['include' => ['readinessState', 'migrations']],
],
```

| Key               | Type                 | Description                                                         |
| ----------------- | -------------------- | ------------------------------------------------------------------- |
| `include`         | list of strings      | Indicator names checked by the group. Each must be a key of `indicators`. |
| `show_details`    | visibility or `null` | Overrides `health.show_details` for this group.                     |
| `show_components` | visibility or `null` | Overrides `health.show_components` for this group.                  |
| `status`          | map                  | `order` and `http_mapping`. Each key overrides the same key of `health.status` for this group. |

An unknown name in `include` throws `InvalidArgumentException` when the group is requested.

### `health.cache`

Reuse each indicator's result for a few seconds, so that frequent probes do not run every check.
This is Spring Boot's `management.endpoint.health.cache.time-to-live`.

```php
'cache' => [
    'ttl' => env('ACTUATOR_HEALTH_CACHE_TTL', 0),
    'store' => env('ACTUATOR_HEALTH_CACHE_STORE', 'file'),
],
```

| Key     | Type          | Description                                                                         |
| ------- | ------------- | ----------------------------------------------------------------------------------- |
| `ttl`   | int (seconds) | How long a result is reused. `0`, the default, turns the cache off. A value that is not a whole number of seconds, 0 or more, logs a warning and turns the cache off. |
| `store` | string        | The Laravel cache store that keeps the results. It must be local to the instance. `null` or an empty string is `file`, never the default store. |

Results are kept per indicator name and definition: the whole endpoint, its groups and its
components share one entry, and a changed class or constructor argument is a new entry. The
`duration` detail of a cached result is the duration of the run that produced it.

The store must never be shared between instances. A result from a shared store, such as `redis` or
`database`, is the result of whichever instance ran the indicator last, so one instance's failure
hides behind another's success. Pick the store for your runtime:

| Runtime                                   | Store   |
| ----------------------------------------- | ------- |
| PHP-FPM, with the APCu extension          | `apc`   |
| Storage local to the container or server  | `file`  |
| Octane (one cache per worker)             | `array` |

Do not use `array` under PHP-FPM: it lives for one request, so nothing is reused. Do not use `file`
when several servers share the `storage` directory.

A store that is missing or cannot be reached never breaks the health endpoint: the indicator runs,
and a warning is logged, once per request under PHP-FPM, once per worker under Octane. A result the store cannot serialize is answered without being cached. A published file
without a `cache` key keeps the cache off.

The cache cuts repeated runs while the checks are fast. Probes that arrive while a slow check runs
all miss the cache and run the check too. Keep the connection and read timeouts of the checked
services short.

Keep the TTL short, 2 to 5 seconds. A longer one keeps a stale `DOWN` and holds the instance out
of traffic after it recovered. See [Kubernetes probes](/laravel-actuator/guides/kubernetes-probes/#cache-the-results).

### `health.metrics`

Export the health status as the [health status gauges](/laravel-actuator/reference/metrics/#health-status)
of the `prometheus` endpoint. `false`, the default, registers nothing. A published configuration
without the key is off too.

```php
'metrics' => env('ACTUATOR_HEALTH_METRICS', false),
```

Each scrape runs every indicator once, unless the [health cache](/laravel-actuator/reference/configuration/#healthcache)
holds its result. An indicator that hangs, such as a database that does not answer, holds the
whole scrape: past Prometheus' `scrape_timeout`, the scrape fails and every metric of the instance
is missing until it recovers. Turn the health cache on, and keep the connect timeouts of your
database, cache and Redis connections short.

## `queue`

```php
'queue' => [
    'queues' => [],
    'metrics' => env('ACTUATOR_QUEUE_METRICS', false),
],
```

| Key       | Description                                                                          |
| --------- | ------------------------------------------------------------------------------------ |
| `queues`  | Map of queue connection name to list of queue names, for example `['redis' => ['default', 'emails']]`. The [`queue` indicator](/laravel-actuator/reference/health-indicators/#queuehealthindicator) watches these queues unless its `queues` argument is set, and the queue gauges export them. Empty, the default, watches the default queue of the default connection. Each connection needs at least one queue name. Any other shape makes the indicator `DOWN` with an `InvalidArgumentException`, and leaves `laravel.queue.size` and `laravel.queue.jobs` out with a warning in the log. |
| `metrics` | Export the [queue gauges](/laravel-actuator/reference/metrics/#queues) of the `prometheus` endpoint: the size of each queue of `queues`, its jobs by state, and the failed jobs. `false`, the default, registers nothing. A published configuration without the key is off too. |

With `metrics` on, each scrape counts the jobs of every queue of `queues`. On the `database` driver,
that is a `COUNT(*)` on the jobs table for each queue and state. A queue connection that hangs
holds the whole scrape: keep its connect timeout short. Under Octane, the gauges and their list of
queues are set when the worker starts: restart Octane after a change.

See [Monitor queues](/laravel-actuator/guides/monitor-queues/).

## `info`

```php
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
```

| Key            | Description                                                                         |
| -------------- | ----------------------------------------------------------------------------------- |
| `middleware`   | Middleware added after the shared `middleware`.                                     |
| `build.path`   | The build info file. `php artisan actuator:build-info` writes it; the `build` and `git` contributors read it. |
| `contributors` | Map of response key to contributor [definition](#definitions). The class must implement `Nwidart\Actuator\Info\InfoContributor`. |

See the [info contributors reference](/laravel-actuator/reference/info-contributors/).

## `env`

```php
'env' => [
    'middleware' => [],
    'show_values' => 'never',
],
```

| Key           | Description                                                                 |
| ------------- | --------------------------------------------------------------------------- |
| `middleware`  | Middleware added after the shared `middleware`.                             |
| `show_values` | [Visibility](#visibility-values) of the values. Hidden values read `******`. |

## `mappings` and `scheduledtasks`

```php
'mappings' => [
    'middleware' => [],
],

'scheduledtasks' => [
    'middleware' => [],
    'show_exceptions' => 'never',
],
```

| Key                              | Description                                                           |
| -------------------------------- | --------------------------------------------------------------------- |
| `mappings.middleware`            | Middleware added after the shared `middleware`.                       |
| `scheduledtasks.middleware`      | Middleware added after the shared `middleware`.                       |
| `scheduledtasks.show_exceptions` | [Visibility](#visibility-values) of the exception message in a task's `lastExecution`. The exception type is always shown. |

## `scheduler`

```php
'scheduler' => [
    'record' => env('ACTUATOR_SCHEDULER_RECORD', true),
    'store' => env('ACTUATOR_SCHEDULER_STORE'),
],
```

| Key      | Description                                                                         |
| -------- | ----------------------------------------------------------------------------------- |
| `record` | Record the last execution of each scheduled task and each tick of `schedule:run`. `false` turns recording off: the `scheduledtasks` endpoint leaves out `lastExecution`, and the `scheduler` indicator reports `UNKNOWN`. |
| `store`  | The cache store for the records. `null` uses the application's default store.      |

The scheduler writes the records, and the web instances read them, so the store must be shared:
`redis`, `database` or `memcached`, for example. The `file` and `array` stores do not work when the
scheduler runs on another host. A task's record expires after 7 days without a run. The tick never
expires. See [monitor the scheduler](/laravel-actuator/guides/monitor-the-scheduler/).

## `prometheus`

```php
'prometheus' => [
    'middleware' => [],
],
```

The middleware added after the shared `middleware` on the
[`prometheus` endpoint](/laravel-actuator/reference/endpoints/prometheus/).

## `metrics`

```php
'metrics' => [
    'enable' => [],
    'distribution' => ['slo' => []],
],
```

These keys apply only to the meters you create with
[`MeterRegistry`](/laravel-actuator/reference/php-api/#meterregistry), and to
[`laravel.health.indicator.duration`](/laravel-actuator/reference/metrics/#recorded-by-laravel-actuator)
and the [health status gauges](/laravel-actuator/reference/metrics/#health-status), which Laravel
Actuator records through it. The other built-in metrics are
switched in `instrumentation` of `config/metrics.php`. See the
[built-in metrics](/laravel-actuator/reference/metrics/).

Laravel merges the package defaults one level deep. If your published file has a `metrics` key,
the keys you leave out of it have no default: copy the whole array.

### `metrics.enable`

Map of a meter name, or the start of one, to a boolean. `false` turns the matching meters off.

```php
'enable' => [
    'orders' => false,               // orders.placed, orders.refunded, ...
    'orders.placed' => true,         // but keep this one
],
```

A key matches a meter with the same name, and every meter whose name starts with the key followed
by a dot. `orders` matches `orders.placed` but not `ordersx`. When several keys match, the longest
wins. A meter that no key matches is on.

A meter that is off records nothing. The series it recorded before are left out of every export:
the `prometheus` endpoint, and the `/metrics` route and `metrics:push` command of laravel-metrics.
Those series stay in the store until you
[clear the store](/laravel-actuator/guides/choose-a-metrics-store/#clear-the-store).

### `metrics.distribution.slo`

Map of a timer or summary name to its histogram buckets: a list of upper bounds in the base unit,
which is seconds for a timer.

```php
'distribution' => [
    'slo' => [
        'payment.charge' => [0.1, 0.25, 0.5, 1, 2.5, 5],
    ],
],
```

The bounds are sorted for you. A meter without an entry, or with an empty list, has only the
`+Inf` bucket: it publishes its count and sum. The `+Inf` bucket is always added.

Each bound adds one series for each combination of tag values of the meter.

The store keeps a meter with other bounds apart from the one with the old bounds, and the scrape
then mixes their samples. After you change the bounds of a meter,
[clear the store](/laravel-actuator/guides/choose-a-metrics-store/#clear-the-store).

## Visibility values

`show_details`, `show_components`, `show_values` and `show_exceptions` accept:

| Value               | Grants access                                                           |
| ------------------- | ----------------------------------------------------------------------- |
| `'never'`           | Never.                                                                  |
| `'always'`          | Always.                                                                 |
| `'when_authorized'` | When the `viewActuator` gate is defined and allows the current user.    |

Any other value throws `InvalidArgumentException`. The gate receives the user that the default
guard resolves. Without authenticating middleware, that user is `null`.

## Definitions

An indicator or contributor definition has one of two forms:

```php
// A class name.
'db' => DatabaseHealthIndicator::class,

// A class name and constructor arguments, by parameter name.
'reportingDb' => [DatabaseHealthIndicator::class, ['connection' => 'reporting']],
```

The Laravel container builds the class on each request. It injects the dependencies it can resolve
and takes the other parameters from the arguments array.

## `{id}.middleware`

Middleware of a [custom endpoint](/laravel-actuator/reference/custom-endpoints/), under a key named
after its ID. The key is not in the published file. Add it when the endpoint needs its own
middleware:

```php
'modules' => [
    'middleware' => ['auth:sanctum'],
],
```

Without the key, the endpoint has only the shared `middleware`.
