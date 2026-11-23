---
title: Write a custom health indicator
description: Report the health of your own dependencies, such as an external API or a queue, with a class that implements HealthIndicator.
---

A health indicator is a class that implements `Nwidart\Actuator\Health\HealthIndicator` and returns
a `Health` value.

## Create the indicator

```php title="app/Health/PaymentGatewayHealthIndicator.php"
<?php

namespace App\Health;

use App\Services\PaymentGateway;
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

The container builds the indicator, so constructor dependencies such as `PaymentGateway` are
injected.

## Register the indicator

Add it to `health.indicators`. The key is the component name:

```php title="config/actuator.php"
'indicators' => [
    // ...
    'paymentGateway' => App\Health\PaymentGatewayHealthIndicator::class,
],
```

## Choose the status

| Factory                         | Use when                                                         | HTTP |
| ------------------------------- | ---------------------------------------------------------------- | ---- |
| `Health::up($details)`          | The component works.                                             | 200  |
| `Health::down($details)`        | The component fails.                                             | 503  |
| `Health::outOfService($details)`| The component works but must not take traffic, for example during a planned outage. | 503  |
| `Health::warning($details)`     | The component works but is degraded, for example a disk at 80%.  | 200  |
| `Health::unknown($details)`     | The indicator cannot tell, for example a check that is not configured. | 200  |

The HTTP status applies when this status is the most severe one. The
[health model](/laravel-actuator/concepts/health-model/) explains the aggregation.

## Add details

Pass details to the factory, or add them one at a time. `Health` is immutable, so each call returns
a new instance:

```php
return Health::up()
    ->withDetail('region', 'eu-west-1')
    ->withDetail('latency', $latency);
```

Details are visible only when `show_details` allows them. Never put secrets in details.

## Handle failures

You do not need to catch exceptions. When `health()` throws, the component reports `DOWN` with the
exception class and message in the `error` detail, and the package logs a warning:

```json
{ "status": "DOWN", "details": { "error": "RuntimeException: Connection refused", "duration": "PT0.002S" } }
```

The same happens when the container cannot build the indicator, for example when the class does
not exist or a constructor dependency cannot be resolved. The other components still report.

To report a failure with your own details, catch the exception and use `withException()`:

```php
try {
    $latency = $this->gateway->ping();
} catch (GatewayTimeout $exception) {
    return Health::down(['timeout' => 2])->withException($exception);
}
```

## Make the indicator configurable

Constructor parameters without a type the container can resolve become configuration arguments:

```php
public function __construct(private PaymentGateway $gateway, private int $maxLatency = 500) {}
```

```php title="config/actuator.php"
'paymentGateway' => [App\Health\PaymentGatewayHealthIndicator::class, ['maxLatency' => 250]],
```

## Keep the check fast

Every health request runs every indicator, and probes call the endpoint every few seconds. The
`duration` detail and the `laravel.health.indicator.duration` histogram show how long each one takes.

- Set a short timeout on network calls, well below your probe timeout.
- Avoid heavy queries. Count rows with an index, or read a cached value.
- If a check is expensive, compute it in a scheduled job, store the result in the cache, and let the
  indicator read the cache.
- Set a short [health cache](/laravel-actuator/reference/configuration/#healthcache), 2 to 5
  seconds, so frequent probes reuse a recent result. It does not help while a check hangs, so the
  timeouts still matter.

## See also

- [Test a health indicator](/laravel-actuator/guides/test-your-indicators/)
- [`Health` and `HealthIndicator` reference](/laravel-actuator/reference/php-api/)
- [Add the indicator to a group](/laravel-actuator/guides/define-health-groups/)
