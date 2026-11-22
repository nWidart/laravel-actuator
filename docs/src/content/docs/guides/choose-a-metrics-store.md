---
title: Choose a metrics store
description: Set up the cache store or the Redis store for metrics, and scrape an application that runs on several servers.
---

PHP processes share no memory. Each web worker, queue worker and scheduler run writes its
measurements to a store, and the request that answers a scrape reads them back. See
[the metrics model](/laravel-actuator/concepts/metrics-model/#why-a-store) for the reasons.

| Store             | Setup                          | Use it for                                 |
| ----------------- | ------------------------------ | ------------------------------------------ |
| `cache` (default) | None                           | Development, small applications            |
| `redis`           | A Laravel Redis connection     | Production                                 |
| A class name      | Your own `MetricsStore`        | Anything else                              |

## Use the cache store

The cache store keeps every series in one cache entry. It is the default and uses your default
cache store:

```ini title=".env"
ACTUATOR_METRICS_STORE=cache
```

To use another cache store, name it:

```ini title=".env"
ACTUATOR_METRICS_CACHE_STORE=file
```

The cache store you pick decides who shares the numbers:

| Cache driver                              | Shared between                                   |
| ----------------------------------------- | ------------------------------------------------ |
| `database`, `redis`, `memcached`, `dynamodb` | Every server, queue worker and scheduler run  |
| `file`                                    | The processes of one server                      |
| `array`                                   | Nothing. Each process sees only its own numbers. |

Each write takes a lock, reads the entry, adds to it and writes it back. Writers wait for each
other. A writer that cannot get the lock within 50 milliseconds drops its measurements. Under heavy
traffic, use the Redis store.

The cache driver must support locks. Every driver of Laravel does, except `apc`.

The whole set of series is one cache entry. `memcached` refuses an entry larger than 1 MB, which is
a few thousand series: prefer the Redis store there.

:::caution
`php artisan cache:clear` also removes the metrics. Prometheus sees this as a counter reset and
handles it. Use a dedicated cache store if you clear the cache often.
:::

## Use the Redis store

The Redis store keeps every series as a field of one hash. A write is one script that Redis runs
atomically, so writers never wait.

```ini title=".env"
ACTUATOR_METRICS_STORE=redis
ACTUATOR_METRICS_REDIS_CONNECTION=default
```

The connection is one of `database.redis` in your application. Both the `phpredis` and the `predis`
client work. The store writes to the key `actuator:metrics`, behind the prefix of the connection.

## Write your own store

Implement `Nwidart\Actuator\Metrics\MetricsStore`:

```php title="app/Metrics/DatabaseMetricsStore.php"
<?php

namespace App\Metrics;

use Nwidart\Actuator\Metrics\MetricsStore;

final class DatabaseMetricsStore implements MetricsStore
{
    public function write(array $increments, array $values): void
    {
        // Add each increment to its series. Overwrite each series in $values.
    }

    public function read(): array
    {
        // Return every series: ['key' => 1.0, ...]
    }

    public function clear(): void
    {
        // Remove every series.
    }
}
```

The keys are opaque strings. Two processes can write at the same time, and an increment must not be
lost when they do.

```ini title=".env"
ACTUATOR_METRICS_STORE=App\Metrics\DatabaseMetricsStore
```

The container builds the class.

## Several servers

What a server returns depends on whether the servers share the store.

**Shared store** (Redis, or a `database`, `redis` or `memcached` cache). Every server returns the
totals of the whole application, queue workers and scheduler included. Scrape one address: the load
balancer or the service name. Do not scrape each server and add the results, because each server
already returns the total.

**One store per server** (a `file` cache, or a Redis server on each host). Each server returns its
own numbers. Scrape every server directly and add them in your queries. Never scrape these servers
through a load balancer: each scrape would reach another server, and the counters would appear to
jump back.

The gauges that describe a machine, such as `php_opcache_*`, `php_fpm_*`, `disk_*` and
`system_load_average_1m`, always describe the server that answered the scrape.

To split the stored meters by server on a shared store, add the host name as a common tag:

```php title="config/actuator.php"
'metrics' => [
    'tags' => ['host' => gethostname()],
],
```

## When the store fails

Metrics never break the work they measure.

| Failure                         | Result                                                                  |
| ------------------------------- | ----------------------------------------------------------------------- |
| A write fails, or the cache refuses the entry | The measurements are dropped. The exception is reported once per process. |
| The lock of the cache store stays taken | The measurements are dropped silently.                          |
| A read fails during a scrape    | The endpoint answers HTTP 503, and Prometheus marks the target as down. |
| `actuator:metrics:clear` cannot get the lock of the cache store | The command fails. Run it again. |

## Clear the store

Series stay in the store until you remove them. A route that no longer exists keeps its series.
Empty the store with:

```bash
php artisan actuator:metrics:clear
```

Prometheus handles the reset of the counters.
