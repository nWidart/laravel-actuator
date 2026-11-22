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
    case Up = 'UP';
    case Unknown = 'UNKNOWN';
}
```

Cases are declared from most to least severe.

| Method                                         | Returns                                                     |
| ---------------------------------------------- | ----------------------------------------------------------- |
| `Status::aggregate(iterable $statuses): Status` | The most severe status in `$statuses`. `Unknown` when empty. |
| `httpStatus(): int`                            | `503` for `Down` and `OutOfService`, `200` otherwise.       |

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
}
```

Registers a custom endpoint class under an ID. Call it in `register()` or `boot()` of a service
provider. An invalid, reserved or duplicate ID throws `InvalidArgumentException`. A call after the
application has booted throws `LogicException`. See
[custom endpoints](/laravel-actuator/reference/custom-endpoints/).

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

## Built-in implementations

- Health indicators: see [health indicators](/laravel-actuator/reference/health-indicators/).
- Info contributors: see [info contributors](/laravel-actuator/reference/info-contributors/).

The built-in classes are `final`. To change one, write your own class that implements the interface.

## Service provider

`Nwidart\Actuator\ActuatorServiceProvider` merges the configuration, publishes it under the
`actuator-config` tag, registers the routes, and adds `base_path` to the maintenance mode exceptions.

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

Only exposed endpoints have a route. Use the names with `route()`, for example
`route('actuator.health')`. For the route names of a custom endpoint, see
[custom endpoints](/laravel-actuator/reference/custom-endpoints/#route-names).
