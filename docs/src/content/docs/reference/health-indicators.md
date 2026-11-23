---
title: Health indicators
description: The built-in health indicators, their constructor arguments, statuses and details.
---

All built-in indicators live in the `Nwidart\Actuator\Health\Indicators` namespace. Arguments are
passed by name in the [definition](/laravel-actuator/reference/configuration/#definitions).

| Default name     | Class                            | Enabled by default |
| ---------------- | -------------------------------- | ------------------ |
| `ping`           | `PingHealthIndicator`            | Yes                |
| `readinessState` | `ReadinessStateHealthIndicator`  | Yes                |
| `diskSpace`      | `DiskSpaceHealthIndicator`       | Yes                |
| `db`             | `DatabaseHealthIndicator`        | Yes                |
| `migrations`     | `MigrationsHealthIndicator`      | Yes                |
| `cache`          | `CacheHealthIndicator`           | Yes                |
| `redis`          | `RedisHealthIndicator`           | No                 |
| `queue`          | `QueueHealthIndicator`           | No                 |
| `failedJobs`     | `FailedJobsHealthIndicator`      | No                 |
| `horizon`        | `HorizonHealthIndicator`         | No                 |
| `scheduler`      | `SchedulerHealthIndicator`       | No                 |

## `PingHealthIndicator`

Always `UP` while the application can serve the request.

Arguments: none.

| Status | When   |
| ------ | ------ |
| `UP`   | Always |

Details: none.

## `ReadinessStateHealthIndicator`

Reports whether the application accepts traffic, based on Laravel's maintenance mode.

Arguments: none.

| Status           | When                                                    |
| ---------------- | ------------------------------------------------------- |
| `OUT_OF_SERVICE` | The application is in maintenance mode (`php artisan down`). |
| `UP`             | Otherwise.                                              |

Details: none. The indicator follows the maintenance driver of the application, `file` or `cache`.

## `DiskSpaceHealthIndicator`

Checks the free space on the disk that holds a path.

| Argument    | Type    | Default             | Description                       |
| ----------- | ------- | ------------------- | --------------------------------- |
| `path`      | string  | `base_path()`       | A path on the disk to check.      |
| `threshold` | integer | `10485760` (10 MB)  | Minimum free space, in bytes.     |

| Status | When                                                                |
| ------ | ------------------------------------------------------------------- |
| `UP`   | Free space is at least `threshold`.                                 |
| `DOWN` | Free space is below `threshold`, or the path does not exist or cannot be read. |

| Detail      | Type    | Description                                        |
| ----------- | ------- | -------------------------------------------------- |
| `total`     | integer | Disk size in bytes. `0` when unknown.              |
| `free`      | integer | Free space in bytes. `0` when unknown.             |
| `threshold` | integer | The configured threshold, in bytes.                |
| `path`      | string  | The checked path.                                  |
| `exists`    | boolean | Whether the free space could be read.              |

## `DatabaseHealthIndicator`

Runs `select 1` on a database connection. On MySQL, MariaDB and PostgreSQL, it also reads
how many client connections are open on the server, from every client, and the most the server
accepts.

| Argument             | Type           | Default | Description                                     |
| -------------------- | -------------- | ------- | ----------------------------------------------- |
| `connection`         | string or null | `null`  | Connection name. `null` uses the default connection. |
| `maxConnectionUsage` | float or null  | `null`  | Share of the server's connection limit, from `0` to `1`, at which the database is `DOWN`, for example `0.9`. `null` never compares. |

| Status | When                     |
| ------ | ------------------------ |
| `UP`   | The query succeeds, and the open connections are below `maxConnectionUsage` of the limit. |
| `DOWN` | The query throws, or the open connections reach `maxConnectionUsage` of the limit. |

| Detail               | Type    | Description                                   |
| -------------------- | ------- | --------------------------------------------- |
| `connection`         | string  | Connection name, for example `mysql`.         |
| `database`           | string  | Driver title, for example `MySQL`, `PostgreSQL`, `SQLite`. |
| `validationQuery`    | string  | `select 1`.                                   |
| `connections`        | integer | Client connections open on the server: `Threads_connected` on MySQL and MariaDB, client backends of `pg_stat_activity` on PostgreSQL. |
| `maxConnections`     | integer | The server's connection limit: `max_connections` on MySQL, MariaDB and PostgreSQL. |
| `maxConnectionUsage` | float   | The configured argument, when it is set.      |

`connections` and `maxConnections` are left out on SQLite and SQL Server, and when the database user may not read
them. The status is then `UP` whatever `maxConnectionUsage` is. Under PHP-FPM, each busy worker
holds one connection, so a server near its limit is about to refuse requests:

```php
'db' => [DatabaseHealthIndicator::class, ['maxConnectionUsage' => 0.9]],
```

On failure, the only detail is `error`.

## `MigrationsHealthIndicator`

Compares the migration files with the migrations table, from the paths `php artisan migrate` reads:
`database/migrations` and those that packages register.

Arguments: none.

| Status           | When                                                            |
| ---------------- | --------------------------------------------------------------- |
| `UP`             | Every migration has run.                                        |
| `OUT_OF_SERVICE` | At least one migration has not run. Migrations that `migrate` skips, with `Migrator::withoutMigrations()` or a `shouldRun()` that returns `false`, do not count. |
| `UNKNOWN`        | The migrations table cannot be read, for example the database is down. |

| Detail    | Type            | Description                                                  |
| --------- | --------------- | ------------------------------------------------------------ |
| `pending` | list of strings | The migrations that have not run, by name. Only when `OUT_OF_SERVICE`. |
| `error`   | string          | Only when `UNKNOWN`: `<exception class>: <exception message>`. |

The default `readiness` group includes it: after a deploy, an instance whose code expects a schema
the database does not have yet gets no traffic until `php artisan migrate` has run. A database
outage makes it `UNKNOWN`, not `DOWN`, so it does not take every instance out of rotation: the `db`
indicator reports the outage.

## `CacheHealthIndicator`

Writes a random value to a cache store, reads it back and deletes it. The key is
`actuator:health:` followed by 16 random characters, with a 10-second lifetime.

| Argument | Type           | Default | Description                                  |
| -------- | -------------- | ------- | -------------------------------------------- |
| `store`  | string or null | `null`  | Store name. `null` uses the default store.   |

| Status | When                                                          |
| ------ | ------------------------------------------------------------- |
| `UP`   | The value read back equals the value written.                 |
| `DOWN` | The value differs or is missing, or the store throws.         |

| Detail  | Type   | Description                                                    |
| ------- | ------ | -------------------------------------------------------------- |
| `store` | string | Store name, for example `redis`.                               |
| `error` | string | When the value differs: `Value read back differs from value written`. |

With the `array` or `null` store, the check always succeeds or always fails, and tells nothing about
a shared cache.

## `RedisHealthIndicator`

Sends `PING` to a Redis connection. Requires the `phpredis` extension or `predis/predis`.

| Argument     | Type           | Default | Description                                      |
| ------------ | -------------- | ------- | ------------------------------------------------ |
| `connection` | string or null | `null`  | Connection name from `database.redis`. `null` uses `default`. |

| Status | When                  |
| ------ | --------------------- |
| `UP`   | `PING` succeeds.      |
| `DOWN` | `PING` throws.        |

| Detail       | Type   | Description             |
| ------------ | ------ | ----------------------- |
| `connection` | string | Connection name.        |

## `QueueHealthIndicator`

Reads the size of each watched queue. On the database driver, each read runs a `COUNT(*)` on the
jobs table. See [Monitor queues](/laravel-actuator/guides/monitor-queues/).

| Argument           | Type            | Default | Description                                      |
| ------------------ | --------------- | ------- | ------------------------------------------------ |
| `queues`           | map or `null`   | `null`  | Connection name to list of queue names, for example `['redis' => ['default', 'emails']]`. `null` reads [`queue.queues`](/laravel-actuator/reference/configuration/#queue). Empty watches the default queue of the default connection. |
| `warningThreshold` | integer or null | `null`  | Size at which a queue is `WARNING`. `null` never is. |
| `downThreshold`    | integer or null | `null`  | Size at which a queue is `DOWN`. `null` never is.    |

The default queue of a connection is its `queue` key in `config/queue.php`, or `default`.

Each connection needs at least one queue name. A single name in place of the list also works:
`['redis' => 'default']`. Any other shape, such as a list without connection names, makes the
indicator `DOWN` with this `error`:

```text
InvalidArgumentException: Watched queues must map a connection name to queue names: ['connection' => ['queue', ...]].
```

| Status    | When                                                                     |
| --------- | ------------------------------------------------------------------------ |
| `UP`      | Every queue is below the thresholds.                                     |
| `WARNING` | A queue reaches `warningThreshold`, and none reaches `downThreshold`.    |
| `DOWN`    | A queue reaches `downThreshold`, a queue cannot be read, or `queues` has the wrong shape. |

| Detail             | Type    | Description                                                       |
| ------------------ | ------- | ----------------------------------------------------------------- |
| `queues`           | map     | Connection name to queue name to the counts below.                |
| `warningThreshold` | integer | The configured argument, when it is set.                          |
| `downThreshold`    | integer | The configured argument, when it is set.                          |

Each queue has these counts:

| Count      | Type    | Description                                                         |
| ---------- | ------- | ------------------------------------------------------------------- |
| `size`     | integer | Every job on the queue, as `Queue::size()` counts it.              |
| `pending`  | integer | Jobs waiting to run. Only when the driver has `pendingSize()`.      |
| `delayed`  | integer | Jobs waiting for their delay. Only when the driver has `delayedSize()`. |
| `reserved` | integer | Jobs a worker runs now. Only when the driver has `reservedSize()`. |
| `error`    | string  | Only when the queue cannot be read: `<exception class>: <exception message>`. It replaces the counts. When a count by state fails, the `size` stays. |

The built-in drivers count `pending`, `delayed` and `reserved` since Laravel 12.20. A queue that
cannot be read does not hide the other connections. Within one connection, the first queue decides:

- When the first queue fails, the indicator takes the connection as down. It does not try the other
  queues of that connection, so a connection that does not answer costs one connect timeout, not one
  per queue. Their `error` is `Skipped: the connection failed on queue [<first queue>]`.
- When a later queue fails, such as a deleted SQS queue, only that queue shows its `error`. The
  indicator still reads the next ones.

Any error on the first queue counts as a connection error, even one of that queue alone. Watch a
queue that may be missing after the others, or on a connection of its own.

```json
{
  "status": "DOWN",
  "details": {
    "queues": {
      "database": { "default": { "size": 12, "pending": 10, "delayed": 1, "reserved": 1 } },
      "redis": {
        "default": { "error": "RedisException: Connection refused" },
        "emails": { "error": "Skipped: the connection failed on queue [default]" }
      }
    },
    "warningThreshold": 100,
    "downThreshold": 1000
  }
}
```

## `FailedJobsHealthIndicator`

Counts the failed jobs that the failer (`queue.failer`) stores.

| Argument           | Type            | Default | Description                                      |
| ------------------ | --------------- | ------- | ------------------------------------------------ |
| `warningThreshold` | integer or null | `1`     | Count at which the indicator is `WARNING`. `null` never is. |
| `downThreshold`    | integer or null | `null`  | Count at which the indicator is `DOWN`. `null` never is.    |
| `minutes`          | integer or null | `null`  | Count only the jobs that failed within this many minutes. `null` counts them all. |
| `connection`       | string or null  | `null`  | Count only the jobs of this queue connection.    |
| `queue`            | string or null  | `null`  | Count only the jobs of this queue.               |

Every failer that Laravel ships counts the jobs, except `dynamodb`. Only the `database` and
`database-uuids` failers count within a window: they compare `failed_at` with the time now.

| Status    | When                                                                 |
| --------- | -------------------------------------------------------------------- |
| `UP`      | The count is below the thresholds.                                   |
| `WARNING` | The count reaches `warningThreshold`, and not `downThreshold`.       |
| `DOWN`    | The count reaches `downThreshold`, or the failer throws.             |
| `UNKNOWN` | The failer cannot count the jobs, or not within a window.            |

| Detail             | Type    | Description                                         |
| ------------------ | ------- | --------------------------------------------------- |
| `connection`       | string  | The configured argument, when it is set.            |
| `queue`            | string  | The configured argument, when it is set.            |
| `minutes`          | integer | The configured argument, when it is set.            |
| `count`            | integer | The failed jobs.                                    |
| `warningThreshold` | integer | The configured argument, when it is set.            |
| `downThreshold`    | integer | The configured argument, when it is set.            |
| `error`            | string  | Only when `UNKNOWN`: the failer class, and why it cannot count. |

Without `minutes`, the count holds every failed job until you retry or delete it, for example with
`php artisan queue:prune-failed`.

## `HorizonHealthIndicator`

Reports the status of the Horizon master supervisors, as `php artisan horizon:status` does.
Requires `laravel/horizon`.

Arguments: none.

| Status    | When                                                              |
| --------- | ----------------------------------------------------------------- |
| `UP`      | Every master supervisor runs.                                     |
| `WARNING` | A master supervisor is paused (`php artisan horizon:pause`).      |
| `DOWN`    | No master supervisor runs: Horizon is inactive.                   |
| `UNKNOWN` | `laravel/horizon` is not installed.                               |

| Detail    | Type    | Description                                                |
| --------- | ------- | ---------------------------------------------------------- |
| `status`  | string  | `running`, `paused` or `inactive`.                         |
| `masters` | integer | The master supervisors, one for each `php artisan horizon` process. |
| `error`   | string  | Only when `UNKNOWN`: `laravel/horizon is not installed`.   |

## `SchedulerHealthIndicator`

Checks that the scheduler ticks. Each `php artisan schedule:run` records the time it starts in the
store set by [`scheduler.store`](/laravel-actuator/reference/configuration/#scheduler).
`schedule:work` starts `schedule:run` every minute. The indicator reads that time back. See
[monitor the scheduler](/laravel-actuator/guides/monitor-the-scheduler/).

| Argument       | Type            | Default | Description                                            |
| -------------- | --------------- | ------- | ------------------------------------------------------ |
| `downAfter`    | integer         | `5`     | Minutes without a tick after which the scheduler is `DOWN`. |
| `warningAfter` | integer or null | `2`     | Minutes without a tick after which the scheduler is `WARNING`. `null` turns `WARNING` off. |

| Status    | When                                                                     |
| --------- | ------------------------------------------------------------------------ |
| `UP`      | The last tick is at most `warningAfter` minutes old.                     |
| `WARNING` | The last tick is more than `warningAfter` and at most `downAfter` minutes old. |
| `DOWN`    | The last tick is more than `downAfter` minutes old, the scheduler never ticked, or the store throws. |
| `UNKNOWN` | `scheduler.record` is `false`, so nothing records the ticks.              |

| Detail         | Type            | Description                                                 |
| -------------- | --------------- | ----------------------------------------------------------- |
| `lastTick`     | string or null  | The last tick, ISO 8601. `null` when the scheduler never ticked. Left out when `UNKNOWN`. |
| `downAfter`    | integer         | The configured argument, in minutes.                        |
| `warningAfter` | integer or null | The configured argument, in minutes.                        |
| `error`        | string          | Only when `UNKNOWN`: why.                                   |

The store must be shared by the scheduler and the instance that runs the check. With the `file` or
`array` store, a check on another host never sees a tick and reports `DOWN`.

## Error detail

An indicator that throws, or whose class the container cannot resolve, reports `DOWN` with one
detail:

| Detail  | Type   | Description                                      |
| ------- | ------ | ------------------------------------------------ |
| `error` | string | `<exception class>: <exception message>`         |
