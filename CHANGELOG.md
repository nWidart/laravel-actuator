# Changelog

All notable changes to `laravel-actuator` will be documented in this file.

## Unreleased

### Changed

- Metrics are recorded, stored and served by [nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics) (`^0.1`), which this package now requires and registers. It records whenever `METRICS_ENABLED` is `true` (the default), into its store: Redis by default, or a Laravel cache store with `METRICS_STORE=cache`. The store, the built-in metrics and the labels of every sample are set in `config/metrics.php`.
- The built-in metrics are those of laravel-metrics, with OpenTelemetry names: `http.server.request.duration`, `db.client.operation.duration`, `messaging.process.duration`, `messaging.client.sent.messages`, `laravel.cache.operations`, `laravel.scheduler.task.duration` and `laravel.exceptions`, and, read during the scrape, `laravel.info`, `php.opcache.*`, `php.fpm.*`, `system.cpu.load_average.1m` and `system.filesystem.usage`. Turn them off with `metrics.instrumentation.*` in `config/metrics.php`.
- Prometheus names follow the OpenTelemetry compatibility rules: dots become underscores, the unit is appended as a word (`_seconds`, `_bytes`), and a counter ends in `_total`. Every sample has an `otel_scope_name` label, `nwidart/laravel-actuator` for the meters of `MeterRegistry`.
- `MeterRegistry` keeps `counter()`, `gauge()`, `timer()`, `summary()`, `tagUsing()`, `tagsFor()`, `describe()`, `enabled()` and `flush()`. A counter or gauge with a callback is read during the scrape and never stored. The units `seconds`, `milliseconds` and `bytes` become `s`, `ms` and `By`.
- A timer or summary without buckets is a Prometheus histogram with only the `+Inf` bucket, no longer a `summary`.
- `tagUsing()` takes the OpenTelemetry name of a built-in metric, such as `http.server.request.duration`. It is `Metrics::attributes()` of laravel-metrics.
- Requests to the actuator routes are left out of `http.server.request.duration`: `actuator.*` is added to `metrics.ignore_routes`.
- `metrics.enable` and `metrics.distribution.slo` apply only to the meters created through `MeterRegistry`. `metrics.distribution.slo` is empty by default.
- In tests, use `Nwidart\Metrics\Facades\Metrics::fake()`.
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
