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

Runs `select 1` on a database connection. On MySQL, MariaDB, PostgreSQL and SQL Server, it also
reads how many connections are open on the server, from every client, and the most the server
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
| `connections`        | integer | Connections open on the server, as `php artisan db:monitor` counts them. On SQL Server, only the running sessions. |
| `maxConnections`     | integer | The server's connection limit: `max_connections` on MySQL, MariaDB and PostgreSQL. |
| `maxConnectionUsage` | float   | The configured argument, when it is set.      |

`connections` and `maxConnections` are left out on SQLite, and when the database user may not read
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
| `OUT_OF_SERVICE` | At least one migration has not run.                             |
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

## Error detail

Any indicator that throws reports `DOWN` with one detail:

| Detail  | Type   | Description                                      |
| ------- | ------ | ------------------------------------------------ |
| `error` | string | `<exception class>: <exception message>`         |
