# Changelog

All notable changes to `laravel-actuator` will be documented in this file.

## Unreleased

### Added

- A `WARNING` health status, for a check that is degraded but still working, and `Health::warning()`. It answers HTTP 200. The default severity order is `DOWN`, `OUT_OF_SERVICE`, `WARNING`, `UP`, `UNKNOWN`.
- `health.status.order` sets the severity order, most severe first, and `health.status.http_mapping` sets the HTTP status code of a status, such as `'WARNING' => 207`. A group overrides either with its own `status` key. Status codes are case-insensitive. An unknown status, a status listed twice or an HTTP status code outside 100 to 599 throws `InvalidArgumentException`. See the [health model](https://nwidart.github.io/laravel-actuator/concepts/health-model/).
- A health result cache: with `health.cache.ttl` above 0, each indicator's result is reused for that many seconds, in the cache store `health.cache.store` (`file` by default). The root, the groups and the component paths share the results. It is off by default. Use a store local to the instance, never a shared one such as Redis. See [Cache the results](https://nwidart.github.io/laravel-actuator/guides/kubernetes-probes/#cache-the-results).
- When details are shown, each component has a `duration` detail: how long its indicator took, as an ISO-8601 duration such as `PT0.012S`.
- The histogram `laravel.health.indicator.duration` (`laravel_health_indicator_duration_seconds`), with the indicator's name in `indicator`, recorded each time an indicator runs.
- The gauges `laravel.health.status`, with a series per indicator (`component`) and status, and `laravel.health.overall.status`, with a series per status for the whole application. A series is 1 for the current status and 0 for the others, so `laravel_health_status{status="DOWN"} == 1` alerts on a failing indicator. See the [metrics reference](https://nwidart.github.io/laravel-actuator/reference/metrics/#health-status).
- A Health row in the sample Grafana dashboard, from `laravel_health_overall_status` and `laravel_health_status`.
- `php artisan actuator:health [path]` checks the health of the application, a group or a component, for `exec` probes on queue worker and scheduler pods. It exits `0` when the status maps to an HTTP 2xx, `1` otherwise, and `2` for an unknown path. `--details` prints the components and their details, `--json` the JSON of the endpoint. It shows everything whatever `show_details` and `show_components` say, and never uses the health cache. See [Probe workers and schedulers](https://nwidart.github.io/laravel-actuator/guides/kubernetes-probes/#probe-workers-and-schedulers).
- `php artisan actuator:build-info` writes the `version`, `time`, `commit` and `branch` of the build to `info.build.path` (`bootstrap/cache/actuator-build.json` by default), from git unless `--build-version`, `--time`, `--commit`, `--branch` or `--path` are given. `BuildInfoContributor`, named `build`, shows that file in `/actuator/info`, and shows nothing without it. See the [guide](https://nwidart.github.io/laravel-actuator/guides/add-build-info/).
- `GitInfoContributor` falls back to the commit and branch of the build info file when the `.git` directory is absent, as in most Docker images.
- `ROADMAP.md`, with the plan to make `Status` accept any status code in 2.0.

### Changed

- When details are shown, every component now has a `details` object, with at least `duration`. A component without details had no `details` key before. An indicator's own `duration` detail wins. Code or tests that compare the exact JSON of the health endpoint see the new key.
- `Status` has a new case, `Warning`. A `match` over `Status` without a default arm needs a `Status::Warning` branch.
- The `build` contributor is in the default contributors. A published configuration keeps its own contributors: add `'build' => BuildInfoContributor::class` to `info.contributors` to get it.
- The health status gauges are on by default, and each scrape of `/actuator/prometheus` runs every health indicator unless the health cache holds its result. Turn the cache on, or turn the gauges off in `actuator.metrics.enable`: `'laravel.health.status' => false` and `'laravel.health.overall.status' => false`, or `'laravel.health' => false`, which also turns off `laravel.health.indicator.duration`.
- `HealthEndpoint` takes a cache factory and the `MeterRegistry` in its constructor, and is no longer `readonly`. Resolve it from the container.

## 1.3.1 - 2026-11-23

### Changed

- Requires nwidart/laravel-metrics `^0.3 || ^1.0`, so `composer update` can install laravel-metrics 1.0 and nwidart/metrics-store 1.0. laravel-metrics 1.0 has no code changes since 0.3.

### Fixed

- A health indicator whose class cannot be resolved (it does not exist, or a constructor dependency cannot be built) reports `DOWN` with the exception in its `error` detail, and logs a warning. The health endpoint no longer fails with HTTP 500 and still reports the other components.
- The "Database server connections" panel of the sample Grafana dashboard shows a count of connections, not operations per second.
- The screenshot of the sample Grafana dashboard in the docs shows the panels. It was blank. Click it to see the whole dashboard.

## 1.3.0 - 2026-11-22

### Added

- A sample Grafana dashboard for the built-in metrics, in `docs/public/grafana/laravel-actuator.json`. See the [guide](https://nwidart.github.io/laravel-actuator/guides/grafana-dashboard/).
- `MigrationsHealthIndicator`, named `migrations`: `OUT_OF_SERVICE` while migrations have not run, with their names in the `pending` detail, and `UNKNOWN` when the migrations table cannot be read. It is in the default indicators and in the default `readiness` group, so new instances get no traffic before `php artisan migrate` has run.
- `DatabaseHealthIndicator` shows the `connections` open on the server and its `maxConnections`, on MySQL, MariaDB and PostgreSQL. With `maxConnectionUsage` (e.g. `0.9`), it is `DOWN` once the open connections reach that share of the limit.
- The database and Eloquent metrics of laravel-metrics 0.3: `laravel.db.transaction.duration`, `laravel.db.queries`, `laravel.eloquent.models`, `laravel.eloquent.models.pruned` and, read during the scrape, `laravel.db.server.connections`, `laravel.db.server.connections.max` and `laravel.db.migrations.pending`.

### Changed

- Requires nwidart/laravel-metrics `^0.3`.
- The sample Grafana dashboard shows the new database and Eloquent metrics in its Database row. Its "Queries per request" panel becomes "p95 queries per request, top routes", from `laravel.db.queries`.
- Without a published `config/actuator.php`, `/actuator/health` and `/actuator/health/readiness` now answer `OUT_OF_SERVICE` (HTTP 503) while migrations are pending. A published configuration keeps its own indicators and groups: add `'migrations' => MigrationsHealthIndicator::class` to `health.indicators`, and `migrations` to the `readiness` group, to get the check.

## 1.2.0 - 2026-11-22

### Changed

- Metrics are recorded, stored and served by [nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics) (`^0.2`), which this package now requires and registers. It records whenever `METRICS_ENABLED` is `true` (the default), into its store: Redis by default, or a Laravel cache store with `METRICS_STORE=cache`. The store, the built-in metrics and the labels of every sample are set in `config/metrics.php`.
- The built-in metrics are those of laravel-metrics, with OpenTelemetry names: `http.server.request.duration`, `db.client.operation.duration`, `messaging.process.duration`, `messaging.client.sent.messages`, `laravel.cache.operations`, `laravel.scheduler.task.duration` and `laravel.exceptions`, and, read during the scrape, `laravel.info`, `php.opcache.*`, `php.fpm.*`, `system.cpu.load_average.1m` and `system.filesystem.usage`. Turn them off with `metrics.instrumentation.*` in `config/metrics.php`.
- Prometheus names follow the OpenTelemetry compatibility rules: dots become underscores, the unit is appended as a word (`_seconds`, `_bytes`), and a counter ends in `_total`. Every sample has an `otel_scope_name` label, `nwidart/laravel-actuator` for the meters of `MeterRegistry`.
- `MeterRegistry` keeps `counter()`, `gauge()`, `timer()`, `summary()`, `tagUsing()`, `tagsFor()`, `describe()`, `enabled()` and `flush()`. A counter or gauge with a callback is read during the scrape and never stored. The units `seconds`, `milliseconds` and `bytes` become `s`, `ms` and `By`.
- A timer or summary without buckets is a Prometheus histogram with only the `+Inf` bucket, no longer a `summary`.
- `tagUsing()` takes the OpenTelemetry name of a built-in metric, such as `http.server.request.duration`. It is `Metrics::attributes()` of laravel-metrics.
- Requests to the actuator routes are left out of `http.server.request.duration`: `actuator.*` is added to `metrics.ignore_routes`.
- `metrics.enable` and `metrics.distribution.slo` apply only to the meters created through `MeterRegistry`. `metrics.distribution.slo` is empty by default.
- In tests, use `Nwidart\Metrics\Facades\Metrics::fake()`.
- A meter turned off with `metrics.enable` also stops serving the series it stored before. With `METRICS_ENABLED=false`, `/actuator/prometheus` serves nothing. A store that cannot be built answers 503, and the application's requests keep working.
- The same meter name with another unit is another metric. A callback registered again with the same tags in another order replaces the first.
- **Breaking:** recording no longer depends on exposing the `prometheus` endpoint. Set `METRICS_ENABLED=false` to record nothing.
- **Breaking:** the configuration keys `metrics.enabled`, `metrics.store`, `metrics.cache`, `metrics.redis` and `metrics.tags` are removed, with `ACTUATOR_METRICS_ENABLED`, `ACTUATOR_METRICS_STORE`, `ACTUATOR_METRICS_CACHE_STORE` and `ACTUATOR_METRICS_REDIS_CONNECTION`. Labels on every sample move to `metrics.prometheus.labels` in `config/metrics.php`.
- **Breaking:** the built-in meters `http.server.requests`, `queue.jobs`, `db.queries`, `exceptions`, `tasks.scheduled.execution`, `php.info`, `disk.free`, `disk.total` and `system.load.average.1m` are removed, with their Prometheus names. The `laravel.info`, OPcache and PHP-FPM meters of this package are replaced by those of laravel-metrics, which have other names, attributes or labels.
- **Breaking:** `MetricsStore`, the `cache` and `redis` stores of this package, `MeterSnapshot`, `MeterType`, `MeterRegistry::collect()`, `clear()` and `collecting()` are removed.

## 1.1.0 - 2026-11-22

### Added

- Custom endpoints: register a class with `Actuator::endpoint('id', Endpoint::class)` from an application or a package service provider. A custom endpoint follows `exposure`, `base_path` and middleware like a built-in one, is listed in the discovery response, and works with `php artisan route:cache`.
- Operation attributes `#[ReadOperation]` (`GET`), `#[WriteOperation]` (`POST`) and `#[DeleteOperation]` (`DELETE`), and `#[Selector]` for path segments.
- Route `actuator.health-path` for `/actuator/health/{path}`.
- `prometheus` endpoint at `/actuator/prometheus`, in the Prometheus text exposition format. Not exposed by default.
- A meter registry modelled on Micrometer: `MeterRegistry` with counters, gauges, timers and distribution summaries, tags, common tags and histogram buckets.
- Built-in meters: `http.server.requests`, `queue.jobs`, `db.queries`, `exceptions`, `tasks.scheduled.execution`, and gauges for PHP, OPcache, PHP-FPM, the system load and the disk.
- Metrics stores: `cache` (default, any Laravel cache store with locks) and `redis`, or your own `MetricsStore`.
- `MeterRegistry::tagUsing()` lets an application or a package add tags to the built-in meters.
- `metrics.enable` turns meters off by name prefix. `ACTUATOR_METRICS_ENABLED=false` turns collection off.
- `actuator:metrics:clear` command.

### Changed

- **Breaking:** the `Nwidart\Actuator\Endpoint` enum is removed. The built-in endpoints are registered through the same mechanism as custom endpoints.
- **Breaking:** the route `actuator.health` no longer accepts a `path` parameter. Use `actuator.health-path`.
- **Breaking:** `routes/actuator.php` is removed. The service provider registers the routes.
- The `mappings` endpoint shows the operation, such as `HealthController@health`, as the action of an actuator route.
- Metrics are only collected while the `prometheus` endpoint is exposed. If you expose every endpoint with `*`, this release starts collecting them: each request then writes to the metrics store once. Add `prometheus` to `exposure.exclude`, or set `ACTUATOR_METRICS_ENABLED=false`, to keep the previous behaviour.

## 1.0.0 - 2026-11-22

First release, rebuilt for Laravel 12 and 13 (PHP 8.2+).

- Discovery endpoint at `/actuator` with links to every exposed endpoint.
- `health` endpoint in the Spring Boot Actuator format: status aggregation, components, details, HTTP 503 for `DOWN` and `OUT_OF_SERVICE`, health groups (`liveness`, `readiness`) and per-component paths.
- Health indicators: `ping`, `readinessState` (maintenance mode), `diskSpace`, `db`, `cache`, `redis`.
- `info` endpoint with `app`, `git`, `php`, `os` and `process` contributors.
- `env`, `mappings` and `scheduledtasks` endpoints.
- Endpoint exposure (`include` / `exclude`), configurable base path and middleware per endpoint.
- `show_details`, `show_components` and `show_values` settings: `never`, `when_authorized` (the `viewActuator` gate) or `always`.
- Actuator routes keep answering in maintenance mode.
