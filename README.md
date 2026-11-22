# Laravel Actuator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/nwidart/laravel-actuator.svg?style=flat-square)](https://packagist.org/packages/nwidart/laravel-actuator)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![CI](https://img.shields.io/github/actions/workflow/status/nwidart/laravel-actuator/php.yml?branch=master&style=flat-square)](https://github.com/nwidart/laravel-actuator/actions/workflows/php.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/nwidart/laravel-actuator.svg?style=flat-square)](https://packagist.org/packages/nwidart/laravel-actuator)

Production-ready endpoints to monitor your Laravel application: health checks for Kubernetes probes and load balancers, metrics for Prometheus, build and runtime info, configuration, routes and scheduled tasks.

**Documentation: [nwidart.github.io/laravel-actuator](https://nwidart.github.io/laravel-actuator/)**

The endpoints follow [Spring Boot Actuator](https://docs.spring.io/spring-boot/reference/actuator/endpoints.html): same paths, same status semantics and, for `health`, `info`, `env` and `scheduledtasks`, the same JSON format. The aim is that tools built for Spring Boot Actuator, such as [Spring Boot Admin](https://github.com/codecentric/spring-boot-admin), can read them.

## Requirements

- PHP 8.2 or later
- Laravel 12 or 13
- Redis for the metrics in production (see [Metrics](#metrics))

## Installation

```bash
composer require nwidart/laravel-actuator
```

The service provider registers itself. To change the defaults, publish the configuration file:

```bash
php artisan vendor:publish --tag=actuator-config
```

## Endpoints

| ID               | Path                                | Description                                      | Exposed by default |
|------------------|-------------------------------------|--------------------------------------------------|--------------------|
|                  | `/actuator`                         | Links to the exposed endpoints.                  | Yes                |
| `health`         | `/actuator/health/{group}/{name}`   | Application health.                              | Yes                |
| `info`           | `/actuator/info`                    | Application, git, PHP, OS and process info.      | No                 |
| `env`            | `/actuator/env`                     | Configuration and environment variables.         | No                 |
| `mappings`       | `/actuator/mappings`                | Registered routes.                               | No                 |
| `scheduledtasks` | `/actuator/scheduledtasks`          | Tasks registered in the scheduler.               | No                 |
| `prometheus`     | `/actuator/prometheus`              | Metrics, in the Prometheus text format.          | No                 |

### Exposure

Only `health` is exposed by default. List the endpoints to expose, or use `*` for all. An excluded endpoint is never exposed:

```php
'exposure' => [
    'include' => ['*'],
    'exclude' => ['env'],
],
```

Change the `/actuator` prefix with `base_path` (or `ACTUATOR_BASE_PATH`).

### Securing endpoints

`info`, `env`, `mappings`, `scheduledtasks` and `prometheus` reveal details about your application. Before you expose them, protect them with middleware. The top-level `middleware` applies to every actuator route. Each endpoint can add its own, so `health` stays public for probes:

```php
'middleware' => [],

'env' => [
    'middleware' => ['web', 'auth', 'can:viewActuator'],
],
```

`show_details`, `show_components` and `show_values` accept three values:

- `never` (default)
- `always`
- `when_authorized`: allowed when the `viewActuator` gate passes

Define the gate in your application, for example in `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewActuator', fn (User $user) => $user->isAdmin());
```

The gate checks the authenticated user, so the route needs middleware that authenticates (for example `web` or `auth:sanctum`).

## Health

```bash
curl http://localhost/actuator/health
```

```json
{"status": "UP", "groups": ["liveness", "readiness"]}
```

With `show_details` set to `always`:

```json
{
  "status": "UP",
  "components": {
    "ping": {"status": "UP"},
    "readinessState": {"status": "UP"},
    "diskSpace": {"status": "UP", "details": {"total": 494384795648, "free": 213212008448, "threshold": 10485760, "path": "/var/www", "exists": true}},
    "db": {"status": "UP", "details": {"connection": "mysql", "database": "MySQL", "validationQuery": "select 1"}},
    "cache": {"status": "UP", "details": {"store": "redis"}}
  },
  "groups": ["liveness", "readiness"]
}
```

The overall status is the most severe component status, in this order: `DOWN`, `OUT_OF_SERVICE`, `UP`, `UNKNOWN`. `DOWN` and `OUT_OF_SERVICE` return HTTP 503. All other statuses return HTTP 200.

An indicator that throws an exception reports `DOWN`, with the exception message in the `error` detail.

`show_components` controls whether components are listed. When it is `null`, it follows `show_details`. A single component is available at `/actuator/health/{name}` when components are shown.

### Indicators

| Name             | Class                            | Status                                                                |
|------------------|----------------------------------|-----------------------------------------------------------------------|
| `ping`           | `PingHealthIndicator`            | Always `UP`.                                                          |
| `readinessState` | `ReadinessStateHealthIndicator`  | `OUT_OF_SERVICE` in maintenance mode (`php artisan down`).            |
| `diskSpace`      | `DiskSpaceHealthIndicator`       | `DOWN` when free space is below `threshold` bytes (default 10 MB).    |
| `db`             | `DatabaseHealthIndicator`        | `DOWN` when `select 1` fails on the connection.                       |
| `cache`          | `CacheHealthIndicator`           | `DOWN` when a value cannot be written and read back.                  |
| `redis`          | `RedisHealthIndicator`           | `DOWN` when `PING` fails. Disabled by default.                        |

An indicator entry is a class name, or a class name with constructor arguments. Use the second form to check a specific connection, store or path, or to register the same indicator twice:

```php
'indicators' => [
    'db' => DatabaseHealthIndicator::class,
    'reportingDb' => [DatabaseHealthIndicator::class, ['connection' => 'reporting']],
    'diskSpace' => [DiskSpaceHealthIndicator::class, ['path' => storage_path(), 'threshold' => 100 * 1024 * 1024]],
    'redis' => [RedisHealthIndicator::class, ['connection' => 'cache']],
],
```

### Custom indicators

Implement `HealthIndicator` and add the class to `health.indicators`. The container resolves it, so constructor dependencies are injected:

```php
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;

final class PaymentGatewayHealthIndicator implements HealthIndicator
{
    public function __construct(private PaymentGateway $gateway) {}

    public function health(): Health
    {
        $latency = $this->gateway->ping();

        return $latency < 500
            ? Health::up(['latency' => $latency])
            : Health::outOfService(['latency' => $latency]);
    }
}
```

`Health` provides `up()`, `down()`, `outOfService()` and `unknown()`, each with optional details, plus `withDetail()` and `withException()`.

### Groups and Kubernetes probes

A group checks a subset of indicators at `/actuator/health/{group}`. A group can override `show_details` and `show_components`:

```php
'groups' => [
    'liveness' => ['include' => ['ping']],
    'readiness' => ['include' => ['readinessState', 'db'], 'show_details' => 'never'],
],
```

The default groups suit Kubernetes probes:

```yaml
livenessProbe:
  httpGet:
    path: /actuator/health/liveness
    port: 80
readinessProbe:
  httpGet:
    path: /actuator/health/readiness
    port: 80
```

Actuator routes keep answering in maintenance mode. During `php artisan down`, the readiness probe returns `OUT_OF_SERVICE` (HTTP 503), so traffic stops, and the liveness probe stays `UP`, so pods are not restarted.

Add external dependencies such as `db` to the readiness group with care: when the shared database fails, every pod becomes unready at the same time.

## Info

```json
{
  "app": {"name": "Laravel", "environment": "production", "laravel": "13.43.0", "timezone": "UTC", "locale": "en"},
  "git": {"branch": "master", "commit": {"id": "49689af"}},
  "php": {"version": "8.4.27", "sapi": "fpm-fcgi"},
  "os": {"name": "Linux", "version": "6.8.0", "arch": "x86_64"},
  "process": {"pid": 42, "memory": {"usage": 2097152, "peak": 2097152, "limit": 134217728}}
}
```

`git` reads the `.git` directory and is omitted when there is none. Memory is in bytes, and a `limit` of `-1` means unlimited.

Add your own section with a class that implements `InfoContributor`:

```php
use Nwidart\Actuator\Info\InfoContributor;

final class BuildInfoContributor implements InfoContributor
{
    public function contribute(): array
    {
        return ['version' => config('app.version'), 'time' => config('app.build_time')];
    }
}
```

```php
'info' => [
    'contributors' => [
        'build' => BuildInfoContributor::class,
        // ...
    ],
],
```

## Env

Lists every configuration key and environment variable in the Spring Boot format. Values are masked as `******` unless `env.show_values` allows them.

## Mappings

Lists every route with its methods, URI, name, action, middleware and domain.

## Scheduled tasks

Lists the tasks of the Laravel scheduler in the Spring Boot format. Tasks that run every minute or less often are listed under `cron`, with their next execution time. Sub-minute tasks (`everyTenSeconds()` and similar) are listed under `fixedRate`, with an interval in milliseconds.

## Metrics

Metrics are recorded by [nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics), which this package requires and registers. It records HTTP requests, database queries, queued jobs, cache operations, scheduled tasks and exceptions with no code, under their [OpenTelemetry](https://opentelemetry.io/docs/specs/semconv/) names. Recording starts at once, into the `default` Redis connection. Without Redis, for development, set `METRICS_STORE=cache` to use a Laravel cache store. `METRICS_ENABLED=false` turns recording off.

Expose the `prometheus` endpoint to let a [Prometheus](https://prometheus.io) server scrape them:

```php
'exposure' => [
    'include' => ['health', 'prometheus'],
],
```

```bash
curl http://localhost/actuator/prometheus
```

```text
# HELP http_server_request_duration_seconds Duration of HTTP server requests.
# TYPE http_server_request_duration_seconds histogram
http_server_request_duration_seconds_bucket{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",network_protocol_version="1.1",otel_scope_name="nwidart/laravel-metrics",url_scheme="https",le="0.1"} 3
http_server_request_duration_seconds_sum{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",network_protocol_version="1.1",otel_scope_name="nwidart/laravel-metrics",url_scheme="https"} 0.0241
http_server_request_duration_seconds_count{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",network_protocol_version="1.1",otel_scope_name="nwidart/laravel-metrics",url_scheme="https"} 3
```

Prometheus names follow the OpenTelemetry compatibility rules: dots become underscores, the unit is appended (`_seconds`, `_bytes`), and a counter ends in `_total`. Every sample has an `otel_scope_name` label. Requests to the actuator routes are left out of `http.server.request.duration`.

### Built-in metrics

| Metric                            | Prometheus name                           |
|-----------------------------------|-------------------------------------------|
| `http.server.request.duration`    | `http_server_request_duration_seconds`    |
| `db.client.operation.duration`    | `db_client_operation_duration_seconds`    |
| `messaging.process.duration`      | `messaging_process_duration_seconds`      |
| `messaging.client.sent.messages`  | `messaging_client_sent_messages_total`    |
| `laravel.cache.operations`        | `laravel_cache_operations_total`          |
| `laravel.scheduler.task.duration` | `laravel_scheduler_task_duration_seconds` |
| `laravel.exceptions`              | `laravel_exceptions_total`                |

These are read during the scrape and never stored: `laravel.info`, `php.opcache.*`, `php.fpm.*`, `system.cpu.load_average.1m` and `system.filesystem.usage`. The [laravel-metrics README](https://github.com/nWidart/laravel-metrics#built-in-instrumentation) lists the attributes of each metric. Turn groups off, change the store, ignore more routes and add labels to every sample in `config/metrics.php` (`php artisan vendor:publish --tag=metrics-config`).

With a shared store, every server returns the totals of the whole application: scrape one address, not each server. `php artisan actuator:metrics:clear` empties the store.

A [sample Grafana dashboard](https://nwidart.github.io/laravel-actuator/guides/grafana-dashboard/) shows the built-in metrics: requests, queries, jobs, cache, scheduled tasks, exceptions and the PHP runtime. Import it, or use it as a starting point.

### Custom metrics

Inject `MeterRegistry`. Its API is modelled on [Micrometer](https://micrometer.io): counters, gauges, timers and distribution summaries, with tags.

```php
use Nwidart\Actuator\Metrics\MeterRegistry;

final class PlaceOrder
{
    public function __construct(private MeterRegistry $metrics) {}

    public function handle(Order $order): void
    {
        $this->metrics->counter('orders.placed', ['channel' => $order->channel])->increment();

        $this->metrics->timer('payment.charge', ['provider' => 'stripe'])->time(fn () => $this->charge($order));
    }
}
```

```text
orders_placed_total{channel="web",otel_scope_name="nwidart/laravel-actuator"} 1
payment_charge_seconds_bucket{otel_scope_name="nwidart/laravel-actuator",provider="stripe",le="+Inf"} 1
payment_charge_seconds_sum{otel_scope_name="nwidart/laravel-actuator",provider="stripe"} 0.153
payment_charge_seconds_count{otel_scope_name="nwidart/laravel-actuator",provider="stripe"} 1
```

A timer or summary has only the `+Inf` bucket until you give it buckets in `metrics.distribution.slo`. Turn your meters off by name prefix with `metrics.enable`:

```php
'metrics' => [
    'enable' => ['orders' => false],
    'distribution' => ['slo' => ['payment.charge' => [0.1, 0.25, 0.5, 1, 2.5, 5]]],
],
```

A gauge takes a callback that is read during the scrape, or a value that you set. Register callbacks in the `boot()` method of a service provider:

```php
public function boot(MeterRegistry $metrics): void
{
    $metrics->gauge('queue.size', ['queue' => 'default'], fn () => Queue::size('default'));
}
```

```php
$metrics->gauge('backup.last.success', unit: 'seconds')->set(time());
```

In tests, `Nwidart\Metrics\Facades\Metrics::fake()` keeps what is recorded in memory and offers assertions such as `assertValue('orders.placed', 1, ['channel' => 'web'])`.

### Tags on the built-in metrics

An application or a package adds its own tag to a built-in metric with `tagUsing()`, by its OpenTelemetry name. The callback receives what the measurement is about, such as the Laravel event:

```php
use Illuminate\Foundation\Http\Events\RequestHandled;
use Nwidart\Actuator\Metrics\MeterRegistry;

public function boot(MeterRegistry $metrics): void
{
    $metrics->tagUsing('http.server.request.duration', fn (RequestHandled $event): array => [
        'module' => Modules::ofRoute($event->request->route()) ?? 'none',
    ]);
}
```

`tagUsing()` is `Metrics::attributes()` of laravel-metrics. Keep the set of tag values small and fixed: each value is a series of its own.

## Custom endpoints

Add your own endpoint from an application or a package. An endpoint is a class with methods marked as read (`GET`), write (`POST`) or delete (`DELETE`) operations. A `#[Selector]` parameter is a path segment:

```php
use Nwidart\Actuator\Endpoints\Attributes\DeleteOperation;
use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;
use Nwidart\Actuator\Endpoints\Attributes\Selector;

final class QueuesEndpoint
{
    #[ReadOperation]                                     // GET /actuator/queues
    public function queues(): array { /* ... */ }

    #[ReadOperation]                                     // GET /actuator/queues/{name}
    public function queue(#[Selector] string $name): ?array { /* ... */ }

    #[DeleteOperation]                                   // DELETE /actuator/queues/{name}
    public function clear(#[Selector] string $name): void { /* ... */ }
}
```

Register the class under an ID in the `register()` method of a service provider:

```php
use Nwidart\Actuator\Actuator;

Actuator::endpoint('queues', QueuesEndpoint::class);
```

The endpoint then behaves like a built-in one:

- It is not exposed by default. Add `queues` to `exposure.include`, or use `*`.
- It is listed in the discovery response, with a templated `queues-name` link for the selector.
- It receives the shared `middleware`, then `queues.middleware` when you add that key to the configuration.
- Its routes work with `php artisan route:cache`.

A read operation that returns `null` answers HTTP 404. A write or delete operation that returns nothing answers HTTP 204. Any other value is sent as JSON, and a response object is sent as it is. Parameters with a class type, such as `Illuminate\Http\Request`, are injected.

Protect an endpoint with write or delete operations before you expose it.

A package registers its endpoint the same way from its own service provider, guarded with `class_exists(Actuator::class)` when Laravel Actuator is optional. See [write a custom endpoint](https://nwidart.github.io/laravel-actuator/guides/write-a-custom-endpoint/).

## Testing

```bash
composer test
composer analyse
composer lint
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email n.widart@gmail.com instead of using the issue tracker.

## Credits

- [Nicolas Widart](https://github.com/nwidart)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
