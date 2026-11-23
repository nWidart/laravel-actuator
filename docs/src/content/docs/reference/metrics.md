---
title: Built-in metrics
description: Every metric that Laravel Actuator serves without code, with its type, Prometheus name and switch.
---

Most built-in metrics come from [nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics#readme),
which Laravel Actuator requires. They use the names of the OpenTelemetry semantic conventions. The
[laravel-metrics documentation](https://nwidart.github.io/laravel-metrics/reference/metrics/http/)
has a page per metric, with its attributes and what it leaves out. This page sums them up.

The Switch column names the key of `config/metrics.php`, under `instrumentation`, that turns a
group of metrics off, with its environment variable. `php artisan vendor:publish --tag=metrics-config`
publishes that file. The [`metrics.enable`](/laravel-actuator/reference/configuration/#metricsenable)
key of `config/actuator.php` does not apply to the metrics of laravel-metrics.

## Recorded metrics

These are recorded when something happens, in every process: web workers, queue workers and
commands. They are kept in the store of laravel-metrics.

| Metric                            | Type      | Prometheus name                            | Switch                                     |
| --------------------------------- | --------- | ------------------------------------------ | ------------------------------------------ |
| `http.server.request.duration`    | Histogram | `http_server_request_duration_seconds`     | `instrumentation.http` (`METRICS_HTTP`)    |
| `db.client.operation.duration`    | Histogram | `db_client_operation_duration_seconds`     | `instrumentation.db` (`METRICS_DB`)        |
| `laravel.db.transaction.duration` | Histogram | `laravel_db_transaction_duration_seconds`  | `instrumentation.db` (`METRICS_DB`)        |
| `laravel.db.queries`              | Histogram | `laravel_db_queries`                       | `instrumentation.db` (`METRICS_DB`)        |
| `laravel.eloquent.models`         | Counter   | `laravel_eloquent_models_total`            | `instrumentation.eloquent` (`METRICS_ELOQUENT`) |
| `laravel.eloquent.models.pruned`  | Counter   | `laravel_eloquent_models_pruned_total`     | `instrumentation.eloquent` (`METRICS_ELOQUENT`) |
| `messaging.process.duration`      | Histogram | `messaging_process_duration_seconds`       | `instrumentation.queue` (`METRICS_QUEUE`)  |
| `messaging.client.sent.messages`  | Counter   | `messaging_client_sent_messages_total`     | `instrumentation.queue` (`METRICS_QUEUE`)  |
| `laravel.cache.operations`        | Counter   | `laravel_cache_operations_total`           | `instrumentation.cache` (`METRICS_CACHE`)  |
| `laravel.scheduler.task.duration` | Histogram | `laravel_scheduler_task_duration_seconds`  | `instrumentation.scheduler` (`METRICS_SCHEDULER`) |
| `laravel.exceptions`              | Counter   | `laravel_exceptions_total`                 | `instrumentation.exceptions` (`METRICS_EXCEPTIONS`) |

| Metric                            | Attributes                                                                   |
| --------------------------------- | ---------------------------------------------------------------------------- |
| `http.server.request.duration`    | `http.request.method`, `http.route`, `http.response.status_code`, `url.scheme`, `network.protocol.version`, `error.type` |
| `db.client.operation.duration`    | `db.system.name`, `db.namespace`, `db.operation.name`, `server.address`, `server.port` |
| `laravel.db.transaction.duration` | `db.system.name`, `db.namespace`, `server.address`, `server.port`, `laravel.db.transaction.result` (`committed`, `rolled_back`) |
| `laravel.db.queries`              | `http.route` for a request, `laravel.job.name` for a queued job               |
| `laravel.eloquent.models`         | `laravel.eloquent.event` (`retrieved`, `created`, `updated`, `deleted`, `restored`), `laravel.eloquent.model` |
| `laravel.eloquent.models.pruned`  | `laravel.eloquent.model`                                                     |
| `messaging.process.duration`      | `messaging.system`, `messaging.operation.name`, `messaging.destination.name`, `laravel.job.name`, `error.type` |
| `messaging.client.sent.messages`  | `messaging.system`, `messaging.operation.name`, `messaging.destination.name`, `laravel.job.name` |
| `laravel.cache.operations`        | `laravel.cache.store`, `laravel.cache.operation`, `laravel.cache.result`     |
| `laravel.scheduler.task.duration` | `laravel.scheduler.task.name`, `error.type`                                  |
| `laravel.exceptions`              | `exception.type`                                                             |

`http.route` is the route template, such as `/users/{user}`, never the raw path.

`laravel.db.transaction.duration` has one value per outer transaction: its count is the number of
transactions, and the `rolled_back` share is the rollback rate. `laravel.db.queries` has one value
per request and per queued job, the number of queries it ran: a route with many queries per request
often has an N+1. `laravel.eloquent.models` counts Eloquent's model events per model class, so
`retrieved` is the number of models hydrated. Mass updates and deletes dispatch no model event and
are not counted.

Requests to the actuator routes are left out of `http.server.request.duration`: Laravel Actuator
adds `actuator.*` to `metrics.ignore_routes`. That key also takes your own patterns, such as `/up`
or `horizon.*`. It applies to no other metric: the queries and cache operations that a health
indicator runs are recorded in `db.client.operation.duration` and `laravel.cache.operations`.

The histograms have buckets out of the box: 5 ms to 10 s for requests and jobs, 1 ms to 10 s for
queries, and 100 ms to 15 min for scheduled tasks.

## Recorded by Laravel Actuator

| Metric                              | Type      | Prometheus name                             | Attributes  |
| ----------------------------------- | --------- | ------------------------------------------- | ----------- |
| `laravel.health.indicator.duration` | Histogram | `laravel_health_indicator_duration_seconds` | `indicator` |

The time each health indicator takes to run, with the indicator's name in `indicator`. It is
recorded each time an indicator runs, not when the [health cache](/laravel-actuator/reference/configuration/#healthcache)
answers. It has the `otel_scope_name` label `nwidart/laravel-actuator`.

It is recorded through `MeterRegistry`, so the keys of `config/actuator.php` apply:
[`metrics.enable`](/laravel-actuator/reference/configuration/#metricsenable) turns it off, with
`'laravel.health' => false`, and
[`metrics.distribution.slo`](/laravel-actuator/reference/configuration/#metricsdistributionslo)
sets its buckets. Without buckets, it keeps only its count and sum.

## Health status

| Metric                          | Type  | Prometheus name                 | Attributes            |
| ------------------------------- | ----- | ------------------------------- | --------------------- |
| `laravel.health.status`         | Gauge | `laravel_health_status`         | `component`, `status` |
| `laravel.health.overall.status` | Gauge | `laravel_health_overall_status` | `status`              |

The health status, so that you can alert on health from the metrics you already scrape.
`laravel.health.status` has a series for each configured health indicator, named in `component`,
and each status: `DOWN`, `OUT_OF_SERVICE`, `WARNING`, `UP` and `UNKNOWN`. A series is 1 for the
current status and 0 for the others, so no series disappears when a status changes.
`laravel.health.overall.status` has the same series for the status of the whole application, the
status of `/actuator/health`.

```text
laravel_health_status{component="db",otel_scope_name="nwidart/laravel-actuator",status="DOWN"} 0
laravel_health_status{component="db",otel_scope_name="nwidart/laravel-actuator",status="UP"} 1
laravel_health_overall_status{otel_scope_name="nwidart/laravel-actuator",status="UP"} 1
```

The application status has its own gauge, rather than a reserved `component` value, so that a sum
or a count over `laravel_health_status` covers only indicators, and so that you can turn the
component series off and keep the application status.

The gauges are read during the scrape and are never stored. Every indicator is in them, whatever
`show_details` and `show_components` say: protect the `prometheus` endpoint instead. An indicator
that throws or cannot be resolved is `DOWN`, and the scrape still succeeds.

They are off by default. Turn them on with
[`health.metrics`](/laravel-actuator/reference/configuration/#healthmetrics):

```ini title=".env"
ACTUATOR_HEALTH_METRICS=true
ACTUATOR_HEALTH_CACHE_TTL=5
```

Each scrape runs every indicator once, unless the [health cache](/laravel-actuator/reference/configuration/#healthcache)
holds its result. An indicator that hangs, such as a database that does not answer, holds the
whole scrape: past Prometheus' `scrape_timeout`, the scrape fails and every metric of the instance
is missing until it recovers. Turn the health cache on, and keep the connect timeouts of your
database, cache and Redis connections short.

[`metrics.enable`](/laravel-actuator/reference/configuration/#metricsenable) still applies: it
turns off only the component series with `'laravel.health.status' => false`, or both gauges and
the duration histogram with `'laravel.health' => false`.

A Prometheus alert when an indicator is down for 2 minutes:

```yaml title="alerts.yml"
groups:
  - name: laravel
    rules:
      - alert: HealthIndicatorDown
        expr: laravel_health_status{status="DOWN"} == 1
        for: 2m
        annotations:
          summary: "{{ $labels.component }} is DOWN on {{ $labels.instance }}"
```

## Queues

| Metric                      | Type  | Prometheus name             | Attributes                     |
| --------------------------- | ----- | --------------------------- | ------------------------------ |
| `laravel.queue.size`        | Gauge | `laravel_queue_size`        | `connection`, `queue`          |
| `laravel.queue.jobs`        | Gauge | `laravel_queue_jobs`        | `connection`, `queue`, `state` |
| `laravel.queue.failed.jobs` | Gauge | `laravel_queue_failed_jobs` |                                |

The backlog of the queues that [`queue.queues`](/laravel-actuator/reference/configuration/#queue)
lists, the same queues the [`queue` health indicator](/laravel-actuator/reference/health-indicators/#queuehealthindicator)
watches. `laravel.queue.size` is the size the driver reports for each queue.
`laravel.queue.jobs` splits it by `state`: `pending`, `delayed` and `reserved`. Only drivers that
count them have these series: the built-in drivers since Laravel 12.20.
`laravel.queue.failed.jobs` is the number of failed jobs the failer holds, of every connection and
queue. It is missing when the failer cannot count them, such as the `dynamodb` failer.

```text
laravel_queue_size{connection="redis",otel_scope_name="nwidart/laravel-actuator",queue="default"} 12
laravel_queue_jobs{connection="redis",otel_scope_name="nwidart/laravel-actuator",queue="default",state="pending"} 10
laravel_queue_failed_jobs{otel_scope_name="nwidart/laravel-actuator"} 4
```

The gauges are read during the scrape and are never stored. Each scrape reads each queue once. A
queue that cannot be read loses its own series, and the scrape still succeeds. A count by state that
fails loses only the series by state of its queue. Every instance reads the same queues: aggregate
with `max`, not `sum`.

They are off by default. Turn them on with
[`queue.metrics`](/laravel-actuator/reference/configuration/#queue):

```ini title=".env"
ACTUATOR_QUEUE_METRICS=true
```

Each scrape then counts the jobs of every watched queue. On the `database` driver, that is a
`COUNT(*)` on the jobs table for each queue and state. A connection that fails on its first queue
is not tried again for its other queues in that scrape, so it costs one connect timeout per scrape.
A connection that answers slowly still holds the whole scrape: past Prometheus' `scrape_timeout`,
the scrape fails and every metric of the instance is missing. Keep the connect timeouts of your
queue connections short.

A badly shaped [`queue.queues`](/laravel-actuator/reference/configuration/#queue) leaves out
`laravel.queue.size` and `laravel.queue.jobs`. Each scrape reports the `InvalidArgumentException`
to the exception handler.

[`metrics.enable`](/laravel-actuator/reference/configuration/#metricsenable) still applies: it
turns off the series by state with `'laravel.queue.jobs' => false`, or every queue gauge with
`'laravel.queue' => false`.

## Metrics read on export

These are read during the scrape, in the process that answers it, and are never stored. They
describe that process and its server. Turn them off with `instrumentation.runtime`
(`METRICS_RUNTIME`).

| Metric                         | Prometheus name                       | Present when                          |
| ------------------------------ | ------------------------------------- | ------------------------------------- |
| `laravel.info`                 | `laravel_info`                        | Always. The value is 1.               |
| `php.opcache.memory.usage`     | `php_opcache_memory_usage_bytes`      | OPcache runs                          |
| `php.opcache.scripts`          | `php_opcache_scripts`                 | OPcache runs                          |
| `php.opcache.lookups`          | `php_opcache_lookups_total`           | OPcache runs                          |
| `php.fpm.processes`            | `php_fpm_processes`                   | PHP runs under PHP-FPM                |
| `php.fpm.listen_queue`         | `php_fpm_listen_queue`                | PHP runs under PHP-FPM                |
| `php.fpm.connections.accepted` | `php_fpm_connections_accepted_total`  | PHP runs under PHP-FPM                |
| `php.fpm.max_children_reached` | `php_fpm_max_children_reached_total`  | PHP runs under PHP-FPM                |
| `php.fpm.slow_requests`        | `php_fpm_slow_requests_total`         | PHP runs under PHP-FPM                |
| `system.cpu.load_average.1m`   | `system_cpu_load_average_1m`          | Not on Windows                        |
| `system.filesystem.usage`      | `system_filesystem_usage_bytes`       | The disk that holds the application   |
| `laravel.db.server.connections` | `laravel_db_server_connections`      | The default connection is MySQL, MariaDB or PostgreSQL |
| `laravel.db.server.connections.max` | `laravel_db_server_connections_max` | The default connection is MySQL, MariaDB or PostgreSQL |
| `laravel.db.migrations.pending` | `laravel_db_migrations_pending`      | Always                                |

`laravel.info` has the attributes `laravel.version`, `process.runtime.name`,
`process.runtime.version` and `php.sapi`. The attributes of the others are in the
[laravel-metrics documentation](https://nwidart.github.io/laravel-metrics/reference/metrics/export-time/).

The three database metrics run a query each during the scrape, and have their own switches:
`instrumentation.db_server` (`METRICS_DB_SERVER`) for the connections, and
`instrumentation.migrations` (`METRICS_MIGRATIONS`) for the pending migrations. A database that
cannot be reached leaves them out; the [`db` health indicator](/laravel-actuator/reference/health-indicators/#databasehealthindicator)
reports it. The same values are in the details of the `db` and `migrations` health indicators.

There are no metrics for the process itself, such as CPU time or memory. Each scrape is answered by
another short-lived process, so these values would not form a series.

## Labels on every sample

Every sample has the `otel_scope_name` label. It is `nwidart/laravel-metrics` for the metrics on
this page, and `nwidart/laravel-actuator` for the `laravel.health.*` and `laravel.queue.*` metrics
and the meters you create with `MeterRegistry`.

`prometheus.labels` in `config/metrics.php` adds your own labels to every sample:

```php title="config/metrics.php"
'prometheus' => [
    // ...
    'labels' => ['application' => 'shop'],
],
```

An attribute of the same name wins over a label from this list.

## Related

- [Add tags to the built-in metrics](/laravel-actuator/guides/tag-built-in-metrics/)
- [The `prometheus` endpoint](/laravel-actuator/reference/endpoints/prometheus/)
- [The metrics model](/laravel-actuator/concepts/metrics-model/)
