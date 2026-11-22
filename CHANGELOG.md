# Changelog

All notable changes to `laravel-actuator` will be documented in this file.

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
