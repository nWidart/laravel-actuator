---
title: PHP API
description: The public classes, interfaces and enums of Laravel Actuator.
---

These are the types you implement or call from your application. The controllers, the classes in
`Nwidart\Actuator\Endpoints` other than the attributes, and `Nwidart\Actuator\Health\HealthEndpoint`
are implementation details and are not described here.

## `HealthIndicator`

```php
namespace Nwidart\Actuator\Health;

interface HealthIndicator
{
    public function health(): Health;
}
```

Reports the health of one component. A thrown exception reports the component as `DOWN`, with the
exception in the `error` detail.

Implementations are built by the container on each request. See
[write a custom health indicator](/laravel-actuator/guides/write-a-health-indicator/).

## `Health`

```php
namespace Nwidart\Actuator\Health;

final readonly class Health
{
    public function __construct(
        public Status $status,
        public array $details = [],
    ) {}
}
```

An immutable health result. `details` is an `array<string, mixed>` that is encoded as JSON.

### Factories

| Method                                   | Returns                        |
| ---------------------------------------- | ------------------------------ |
| `Health::up(array $details = [])`        | Status `UP`                    |
| `Health::down(array $details = [])`      | Status `DOWN`                  |
| `Health::outOfService(array $details = [])` | Status `OUT_OF_SERVICE`     |
| `Health::warning(array $details = [])`   | Status `WARNING`               |
| `Health::unknown(array $details = [])`   | Status `UNKNOWN`               |

### Methods

| Method                                           | Returns                                                         |
| ------------------------------------------------ | --------------------------------------------------------------- |
| `withDetail(string $key, mixed $value): Health`  | A copy with the detail added. An existing key is replaced.      |
| `withException(Throwable $exception): Health`    | A copy with the `error` detail set to `<class>: <message>`.     |

```php
Health::down(['host' => 'payments.internal'])
    ->withDetail('timeout', 2)
    ->withException($exception);
```

## `Status`

```php
namespace Nwidart\Actuator\Health;

enum Status: string
{
    case Down = 'DOWN';
    case OutOfService = 'OUT_OF_SERVICE';
    case Warning = 'WARNING';
    case Up = 'UP';
    case Unknown = 'UNKNOWN';
}
```

Cases are declared from most to least severe.

| Method                                         | Returns                                                     |
| ---------------------------------------------- | ----------------------------------------------------------- |
| `Status::aggregate(iterable $statuses, array $order = []): Status` | The most severe status in `$statuses`. `$order` lists statuses most severe first; the others follow in declaration order. `Unknown` when empty. |
| `httpStatus(): int`                            | `503` for `Down` and `OutOfService`, `200` otherwise.       |

These are the defaults. The health endpoint applies
[`health.status`](/laravel-actuator/reference/configuration/#healthstatus) on top of them.

```php
Status::aggregate([Status::Up, Status::OutOfService]); // Status::OutOfService
Status::aggregate([]);                                 // Status::Unknown
Status::Down->httpStatus();                            // 503
```

## `InfoContributor`

```php
namespace Nwidart\Actuator\Info;

interface InfoContributor
{
    /** @return array<string, mixed> */
    public function contribute(): array;
}
```

Returns the data shown under the contributor's key. An empty array omits the key. See
[customise the info endpoint](/laravel-actuator/guides/write-an-info-contributor/).

## `Visibility`

```php
namespace Nwidart\Actuator;

enum Visibility: string
{
    public const GATE = 'viewActuator';

    case Never = 'never';
    case WhenAuthorized = 'when_authorized';
    case Always = 'always';
}
```

| Member                                     | Description                                                                 |
| ------------------------------------------ | --------------------------------------------------------------------------- |
| `Visibility::GATE`                         | The gate name, `viewActuator`.                                              |
| `Visibility::fromConfig(mixed $value): Visibility` | Parses a configuration value. Throws `InvalidArgumentException` for anything else. |
| `isGranted(): bool`                        | `Never`: `false`. `Always`: `true`. `WhenAuthorized`: the gate is defined and allows the current user. |

## `Actuator`

```php
namespace Nwidart\Actuator;

final class Actuator
{
    /** @param class-string $class */
    public static function endpoint(string $id, string $class): void;

    /** @param Closure(Event): array<mixed> $callback */
    public static function tagScheduledTasksUsing(Closure $callback): void;
}
```

Registers a custom endpoint class under an ID. Call it in `register()` or `boot()` of a service
provider. An invalid, reserved or duplicate ID throws `InvalidArgumentException`. A call after the
application has booted throws `LogicException`. See
[custom endpoints](/laravel-actuator/reference/custom-endpoints/).

`tagScheduledTasksUsing()` adds tags to the entries of the `scheduledtasks` endpoint. The callback
receives each `Illuminate\Console\Scheduling\Event` and returns an array of tags. See
[tags](/laravel-actuator/reference/endpoints/scheduledtasks/#tags).

`Actuator` is also the class to test for when Laravel Actuator is an optional dependency:
`class_exists(\Nwidart\Actuator\Actuator::class)`.

## Operation attributes

```php
namespace Nwidart\Actuator\Endpoints\Attributes;

#[Attribute(Attribute::TARGET_METHOD)]
final class ReadOperation {}

#[Attribute(Attribute::TARGET_METHOD)]
final class WriteOperation {}

#[Attribute(Attribute::TARGET_METHOD)]
final class DeleteOperation {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Selector
{
    public function __construct(public readonly bool $remaining = false) {}
}
```

| Attribute            | Target    | Meaning                                                                   |
| -------------------- | --------- | ------------------------------------------------------------------------- |
| `ReadOperation`      | Method    | The method answers `GET`.                                                 |
| `WriteOperation`     | Method    | The method answers `POST`.                                                |
| `DeleteOperation`    | Method    | The method answers `DELETE`.                                              |
| `Selector`           | Parameter | The parameter is a path segment. `remaining: true` matches the whole remaining path. |

## `MeterRegistry`

```php
namespace Nwidart\Actuator\Metrics;

final class MeterRegistry
{
    public const SCOPE = 'nwidart/laravel-actuator';
}
```

Creates meters, with an API modelled on Micrometer. Each meter is an OpenTelemetry instrument of
[nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics#readme), with the instrumentation
scope `MeterRegistry::SCOPE`. It is a container singleton: inject it, or resolve it with
`app(MeterRegistry::class)`. See
[record your own metrics](/laravel-actuator/guides/record-custom-metrics/).

Tags are an `array<string, string|int|float|bool>`. A tag with an empty name is dropped.

The registry keeps a meter by its name and its unit: the same name with another unit is another
metric.

| Method                                                                        | Description                                                        |
| ----------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| `counter(string $name, array $tags = [], ?Closure $value = null): Counter`    | A total that only goes up. With `$value`, the total is read from the callback during the scrape and never stored, and `increment()` does nothing. |
| `gauge(string $name, array $tags = [], ?Closure $value = null, string $unit = ''): Gauge` | A value that goes up and down. With `$value`, it is read from the callback during the scrape and never stored. A callback that returns `null` leaves the gauge out. |
| `timer(string $name, array $tags = []): Timer`                                | Counts events and their duration, as a histogram in seconds.       |
| `summary(string $name, array $tags = [], string $unit = ''): DistributionSummary` | Counts events and the sum of an amount, as a histogram in `$unit`. |
| `tagUsing(string $meter, Closure $callback): void`                            | Adds tags to a meter recorded by someone else, such as `http.server.request.duration`. The callback receives the object the measurement is about and returns an array of tags. It is `Metrics::attributes()` of laravel-metrics. See [add tags to the built-in metrics](/laravel-actuator/guides/tag-built-in-metrics/). |
| `tagsFor(string $meter, object $context): array`                              | The tags that the `tagUsing()` callbacks of the meter return for `$context`. A callback that throws is skipped, and so is a value that is not a string, a number or a boolean. |
| `describe(string $name, string $description): void`                           | Sets the help text of a meter. Call it before the meter is first used. |
| `enabled(string $name): bool`                                                 | Whether [`metrics.enable`](/laravel-actuator/reference/configuration/#metricsenable) leaves the meter on. |
| `flush(): void`                                                               | Writes what was recorded to the store now. Called for you at the end of each request, job and command. Never throws. |

`$unit` is `seconds`, `milliseconds` or `bytes`, which become the OpenTelemetry units `s`, `ms` and
`By`. Another unit is passed on as it is. An empty unit is no unit.

A callback registered again for the same meter and the same tags, in any order, replaces the
first. A callback that throws is skipped during the scrape, and its exception is reported once.
With `METRICS_ENABLED=false`, every meter records nothing.

## Meters

All four are `final` classes in `Nwidart\Actuator\Metrics`, created by the registry.

| Class                 | Method                                   | Description                                                   |
| --------------------- | ---------------------------------------- | ------------------------------------------------------------- |
| `Counter`             | `increment(float $amount = 1.0): void`   | Adds to the total. A negative amount throws `InvalidArgumentException`. |
| `Gauge`               | `set(float $value): void`                | Stores the value. The last value set wins, across every process. |
| `Timer`               | `record(float $seconds): void`           | Records one duration. A negative duration counts as zero.     |
| `Timer`               | `time(Closure $callback): mixed`         | Runs the callback, records its duration, also when it throws, and returns its result. |
| `DistributionSummary` | `record(float $amount): void`            | Records one amount.                                           |

A value that is not finite (`NAN`, `INF`) is ignored by every meter.

## Built-in implementations

- Health indicators: see [health indicators](/laravel-actuator/reference/health-indicators/).
- Info contributors: see [info contributors](/laravel-actuator/reference/info-contributors/).

The built-in classes are `final`. To change one, write your own class that implements the interface.

## Service provider

`Nwidart\Actuator\ActuatorServiceProvider` merges the configuration, publishes it under the
`actuator-config` tag, registers the routes, and adds `base_path` to the maintenance mode exceptions.
It registers `Nwidart\Metrics\MetricsServiceProvider` of laravel-metrics, also when package
discovery is off, and adds `actuator.*` to `metrics.ignore_routes`. It binds `MeterRegistry` as a
singleton, and builds it when the application boots, in every process. When
[`metrics.enable`](/laravel-actuator/reference/configuration/#metricsenable) is not empty, it
registers a `Metrics::exclude()` filter that leaves the meters turned off out of every export. It
registers the `actuator:build-info`, `actuator:health` and `actuator:metrics:clear` commands.

## Artisan commands

| Command                              | Description                          |
| ------------------------------------ | ------------------------------------ |
| `php artisan actuator:build-info`    | Writes the build info file shown by the `build` contributor, at build time. |
| `php artisan actuator:health [path]` | Checks the health of the application, a group or a component, such as `readiness/db`. |
| `php artisan actuator:metrics:clear` | Removes every series from the store of laravel-metrics. |

`actuator:health` runs the same checks as the [health endpoint](/laravel-actuator/reference/endpoints/health/),
for `exec` probes. It shows everything whatever `show_details` and `show_components` say, and never
uses the health cache.

| Option      | Description                                                    |
| ----------- | -------------------------------------------------------------- |
| `--details` | Prints the components and their details under the status.      |
| `--json`    | Prints the same JSON as the endpoint, with every detail.       |

| Exit code | Meaning                                                          |
| --------- | ---------------------------------------------------------------- |
| `0`       | The status maps to an HTTP 2xx, with `status.http_mapping` of the path. |
| `1`       | The status maps to any other HTTP status.                        |
| `2`       | The group or component does not exist.                           |

## Routes

| Name                       | Path                         |
| -------------------------- | ---------------------------- |
| `actuator.discovery`       | `/{base_path}`               |
| `actuator.health`          | `/{base_path}/health`        |
| `actuator.health-path`     | `/{base_path}/health/{path}` |
| `actuator.info`            | `/{base_path}/info`          |
| `actuator.env`             | `/{base_path}/env`           |
| `actuator.mappings`        | `/{base_path}/mappings`      |
| `actuator.scheduledtasks`  | `/{base_path}/scheduledtasks` |
| `actuator.prometheus`      | `/{base_path}/prometheus`    |

Only exposed endpoints have a route. Use the names with `route()`, for example
`route('actuator.health')`. For the route names of a custom endpoint, see
[custom endpoints](/laravel-actuator/reference/custom-endpoints/#route-names).
