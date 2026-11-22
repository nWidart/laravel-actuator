---
title: Choose a metrics store
description: Pick the Redis store or a cache store for metrics, and scrape an application that runs on several servers.
---

PHP processes share no memory. Each web worker, queue worker and scheduler run writes its
measurements to a store, and the request that answers a scrape reads them back. See
[the metrics model](/laravel-actuator/concepts/metrics-model/#why-a-store) for the reasons.

The store belongs to [nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics#stores).
Set it in `config/metrics.php`, or with environment variables. Publish the file with
`php artisan vendor:publish --tag=metrics-config`.

| `METRICS_STORE`   | Use it for                | Set with                                                  |
| ----------------- | ------------------------- | --------------------------------------------------------- |
| `redis` (default) | Production                | `METRICS_REDIS_CONNECTION`, default `default`             |
| `cache`           | Development without Redis | `METRICS_CACHE_STORE`, default: the default cache store   |

```ini title=".env"
METRICS_STORE=cache
METRICS_CACHE_STORE=file
```

With the `cache` store, writers wait for each other under a lock, and `php artisan cache:clear` also
removes the metrics. The laravel-metrics README lists the details.

## Several servers

What a server returns depends on whether the servers share the store.

**Shared store** (one Redis, or a `database`, `redis` or `memcached` cache). Every server returns
the totals of the whole application, queue workers and scheduler included. Scrape one address: the
load balancer or the service name. Do not scrape each server and add the results, because each
server already returns the total.

**One store per server** (a `file` cache, or a Redis server on each host). Each server returns its
own numbers. Scrape every server directly and add them in your queries. Never scrape these servers
through a load balancer: each scrape would reach another server, and the counters would appear to
jump back.

The metrics read on export, such as `php_opcache_*`, `php_fpm_*`, `system_filesystem_usage_bytes`
and `system_cpu_load_average_1m`, always describe the server that answered the scrape.

## When the store fails

Metrics never break the work they measure.

| Failure                         | Result                                                                  |
| ------------------------------- | ----------------------------------------------------------------------- |
| A write fails                   | The measurements are dropped. The request, job or command goes on. OpenTelemetry writes a warning to the PHP error log, not to the Laravel log. |
| A read fails during a scrape    | The endpoint answers HTTP 503, and Prometheus marks the target as down. The exception is reported. |
| The store cannot be built, such as an unknown `METRICS_STORE` | The exception is reported and nothing is recorded. The request, job or command goes on. The endpoint answers HTTP 503. |

An application without Redis, and without `METRICS_STORE=cache`, fails each write: its requests
keep working, and each one that recorded something writes the warning.

## Clear the store

Series stay in the store until you remove them. A route that no longer exists keeps its series.
Empty the store with:

```bash
php artisan actuator:metrics:clear
```

Prometheus handles the reset of the counters.
