# Changelog

All notable changes to `laravel-actuator` will be documented in this file.

## Unreleased

### Added

- Custom endpoints: register a class with `Actuator::endpoint('id', Endpoint::class)` from an application or a package service provider. A custom endpoint follows `exposure`, `base_path` and middleware like a built-in one, is listed in the discovery response, and works with `php artisan route:cache`.
- Operation attributes `#[ReadOperation]` (`GET`), `#[WriteOperation]` (`POST`) and `#[DeleteOperation]` (`DELETE`), and `#[Selector]` for path segments.
- Route `actuator.health-path` for `/actuator/health/{path}`.

### Changed

- **Breaking:** the `Nwidart\Actuator\Endpoint` enum is removed. The built-in endpoints are registered through the same mechanism as custom endpoints.
- **Breaking:** the route `actuator.health` no longer accepts a `path` parameter. Use `actuator.health-path`.
- **Breaking:** `routes/actuator.php` is removed. The service provider registers the routes.
- The `mappings` endpoint shows the operation, such as `HealthController@health`, as the action of an actuator route.

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
