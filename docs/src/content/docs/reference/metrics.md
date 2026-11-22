---
title: Built-in metrics
description: Every metric that Laravel Actuator serves without code, with its type, Prometheus name and switch.
---

The built-in metrics come from [nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics#readme),
which Laravel Actuator requires. They use the names of the OpenTelemetry semantic conventions. The
laravel-metrics README lists the attributes of each metric and what it leaves out. This page sums
them up.

The Switch column names the key of `config/metrics.php`, under `instrumentation`, that turns a
group of metrics off, with its environment variable. `php artisan vendor:publish --tag=metrics-config`
publishes that file. The [`metrics.enable`](/laravel-actuator/reference/configuration/#metricsenable)
key of `config/actuator.php` does not apply to these metrics.

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
[laravel-metrics README](https://github.com/nWidart/laravel-metrics#metrics-read-on-export).

The three database metrics run a query each during the scrape, and have their own switches:
`instrumentation.db_server` (`METRICS_DB_SERVER`) for the connections, and
`instrumentation.migrations` (`METRICS_MIGRATIONS`) for the pending migrations. A database that
cannot be reached leaves them out; the [`db` health indicator](/laravel-actuator/reference/health-indicators/#databasehealthindicator)
reports it. The same values are in the details of the `db` and `migrations` health indicators.

There are no metrics for the process itself, such as CPU time or memory. Each scrape is answered by
another short-lived process, so these values would not form a series.

## Labels on every sample

Every sample has the `otel_scope_name` label. It is `nwidart/laravel-metrics` for the metrics on
this page, and `nwidart/laravel-actuator` for the meters you create with `MeterRegistry`.

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
