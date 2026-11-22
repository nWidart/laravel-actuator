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
| `health.groups`                  | map of groups           | `liveness`, `readiness`                  |                                |
| `info.middleware`                | list of strings         | `[]`                                     |                                |
| `info.contributors`              | map of definitions      | `app`, `git`, `php`, `os`, `process`     |                                |
| `env.middleware`                 | list of strings         | `[]`                                     |                                |
| `env.show_values`                | visibility              | `'never'`                                |                                |
| `mappings.middleware`            | list of strings         | `[]`                                     |                                |
| `scheduledtasks.middleware`      | list of strings         | `[]`                                     |                                |
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
    'groups' => [/* ... */],
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

An unknown name in `include` throws `InvalidArgumentException` when the group is requested.

## `info`

```php
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
```

| Key            | Description                                                                         |
| -------------- | ----------------------------------------------------------------------------------- |
| `middleware`   | Middleware added after the shared `middleware`.                                     |
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
],
```

Each key holds the middleware added after the shared `middleware`.

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
[`MeterRegistry`](/laravel-actuator/reference/php-api/#meterregistry). The built-in metrics are
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

`show_details`, `show_components` and `show_values` accept:

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
