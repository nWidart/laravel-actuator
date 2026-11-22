---
title: Built-in meters
description: Every meter that Laravel Actuator records, with its type, tags and Prometheus name.
---

The meters below exist without any code in your application. Turn one off with
[`metrics.enable`](/laravel-actuator/reference/configuration/#metricsenable).

## Recorded meters

These are recorded when something happens, in every process: web workers, queue workers and
commands. They are kept in the [metrics store](/laravel-actuator/guides/choose-a-metrics-store/).

| Meter                       | Type    | Prometheus name                      | Default buckets |
| --------------------------- | ------- | ------------------------------------ | --------------- |
| `http.server.requests`      | Timer   | `http_server_requests_seconds`       | 5 ms to 10 s    |
| `queue.jobs`                | Timer   | `queue_jobs_seconds`                 | 10 ms to 5 min  |
| `db.queries`                | Timer   | `db_queries_seconds`                 | 1 ms to 5 s     |
| `tasks.scheduled.execution` | Timer   | `tasks_scheduled_execution_seconds`  | 100 ms to 15 min |
| `exceptions`                | Counter | `exceptions_total`                   |                 |

### `http.server.requests`

One observation for each HTTP request, from the start of the request until the response is ready.
The name and the tags are those of Spring Boot.

| Tag         | Values                                                                                     |
| ----------- | ------------------------------------------------------------------------------------------ |
| `method`    | `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`, or `OTHER`                     |
| `uri`       | The route pattern, such as `/users/{user}`. Without a route: `NOT_FOUND` for a 404, `REDIRECTION` for a 3xx, else `UNKNOWN`. |
| `status`    | The HTTP status code                                                                       |
| `outcome`   | `INFORMATIONAL`, `SUCCESS`, `REDIRECTION`, `CLIENT_ERROR`, `SERVER_ERROR` or `UNKNOWN`     |
| `exception` | The class name of the exception that produced the response, without namespace, or `none`   |

The raw path is never a tag, so a path with an id in it does not create a series of its own.
Requests to the actuator endpoints are not counted. The time to send a streamed response is not
included. A request that PHP ends with a fatal error, such as a timeout or exhausted memory, is not
counted.

Tag contributors receive `Illuminate\Foundation\Http\Events\RequestHandled`.

### `queue.jobs`

One observation for each attempt to run a queued job.

| Tag          | Values                                                                 |
| ------------ | ---------------------------------------------------------------------- |
| `connection` | The queue connection, such as `redis`                                  |
| `queue`      | The queue name                                                         |
| `job`        | The job class, or its display name                                     |
| `outcome`    | See below                                                              |

| `outcome`  | The attempt                                                              |
| ---------- | ------------------------------------------------------------------------ |
| `SUCCESS`  | Finished.                                                                |
| `RELEASED` | Was released back to the queue without an exception, for example by a rate limiter. |
| `RETRY`    | Threw, and the job will be attempted again.                              |
| `FAILED`   | Threw or was marked as failed, and the job will not be attempted again.  |

A job that throws twice and then succeeds counts two `RETRY` attempts and one `SUCCESS`. A queue
worker writes its measurements to the store after each job.

The `queue` tag is only bounded when your queue names are. If you create queues at runtime, such as
one for each tenant, replace the tag with a fixed value:
`$metrics->tagUsing('queue.jobs', fn () => ['queue' => 'tenant'])`.

Tag contributors receive `Illuminate\Queue\Events\JobProcessed`, or
`Illuminate\Queue\Events\JobExceptionOccurred` when the job threw.

### `db.queries`

One observation for each database query, with the duration that Laravel measures.

| Tag          | Values                                |
| ------------ | ------------------------------------- |
| `connection` | The database connection, such as `mysql` |

The SQL is never a tag. Queries that the metrics store itself runs are not counted.

Tag contributors receive `Illuminate\Database\Events\QueryExecuted`.

### `exceptions`

Counts the exceptions that the application reports, which are the ones that reach your log.
These are not counted:

- exceptions that Laravel does not report, such as a 404 or a validation failure,
- an exception class that has its own `report()` method,
- an exception for which one of your `reportable()` callbacks returns `false`,
- any exception, when your exception handler does not extend the one of Laravel.

| Tag         | Values                                          |
| ----------- | ----------------------------------------------- |
| `exception` | The class name of the exception, without namespace |

Tag contributors receive the `Throwable`.

### `tasks.scheduled.execution`

One observation for each run of a scheduled task. The name is that of Spring Boot.

| Tag       | Values                                                                                     |
| --------- | ------------------------------------------------------------------------------------------ |
| `task`    | The [target](/laravel-actuator/reference/endpoints/scheduledtasks/#targets) of the task, as on the `scheduledtasks` endpoint |
| `outcome` | `SUCCESS`, or `FAILED` when the task threw or exited with a failure code                   |

Skipped tasks are not counted. A task that runs in the background is timed until it has started,
not until it has finished.

The target of a command includes its arguments. An argument that changes on each run, such as a
date, creates a series for each value: give such a task a `description()`.

Tag contributors receive `Illuminate\Console\Events\ScheduledTaskFinished`, or
`Illuminate\Console\Events\ScheduledTaskFailed` when the task threw.

## Meters read during the scrape

These are read when the endpoint is requested, and are not stored. They describe the server that
answers the scrape.

| Meter                          | Type    | Prometheus name                    | Tags              | Present when          |
| ------------------------------ | ------- | ---------------------------------- | ----------------- | --------------------- |
| `php.info`                     | Gauge   | `php_info`                         | `version`, `sapi` | Always. The value is 1. |
| `laravel.info`                 | Gauge   | `laravel_info`                     | `version`         | Always. The value is 1. |
| `php.opcache.memory.used`      | Gauge   | `php_opcache_memory_used_bytes`    |                   | OPcache is enabled    |
| `php.opcache.memory.free`      | Gauge   | `php_opcache_memory_free_bytes`    |                   | OPcache is enabled    |
| `php.opcache.memory.wasted`    | Gauge   | `php_opcache_memory_wasted_bytes`  |                   | OPcache is enabled    |
| `php.opcache.scripts.cached`   | Gauge   | `php_opcache_scripts_cached`       |                   | OPcache is enabled    |
| `php.opcache.hits`             | Counter | `php_opcache_hits_total`           |                   | OPcache is enabled    |
| `php.opcache.misses`           | Counter | `php_opcache_misses_total`         |                   | OPcache is enabled    |
| `php.fpm.processes`            | Gauge   | `php_fpm_processes`                | `state`: `active` or `idle` | PHP runs under PHP-FPM |
| `php.fpm.listen.queue`         | Gauge   | `php_fpm_listen_queue`             |                   | PHP runs under PHP-FPM |
| `php.fpm.connections.accepted` | Counter | `php_fpm_connections_accepted_total` |                 | PHP runs under PHP-FPM |
| `php.fpm.max.children.reached` | Counter | `php_fpm_max_children_reached_total` |                 | PHP runs under PHP-FPM |
| `php.fpm.slow.requests`        | Counter | `php_fpm_slow_requests_total`      |                   | PHP runs under PHP-FPM |
| `system.load.average.1m`       | Gauge   | `system_load_average_1m`           |                   | Not on Windows        |
| `disk.free`                    | Gauge   | `disk_free_bytes`                  |                   | Always                |
| `disk.total`                   | Gauge   | `disk_total_bytes`                 |                   | Always                |

`disk.free` and `disk.total` measure the disk that holds the application. `system.load.average.1m`,
`disk.free` and `disk.total` use the names of Spring Boot.

There are no meters for the process itself, such as CPU time, uptime or memory. Each scrape is
answered by another short-lived process, so these values would not form a series.

## Common tags

The tags of [`metrics.tags`](/laravel-actuator/reference/configuration/#metricstags) are added to
every meter above, and to your own.
