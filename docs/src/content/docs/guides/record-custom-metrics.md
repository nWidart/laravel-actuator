---
title: Record your own metrics
description: Count events, time operations and publish gauges from your application or your package.
---

Inject `Nwidart\Actuator\Metrics\MeterRegistry` and create a meter. A meter has a dotted name and
optional tags. The [metrics model](/laravel-actuator/concepts/metrics-model/) explains each type.

Expose the `prometheus` endpoint first, see
[collect metrics with Prometheus](/laravel-actuator/guides/collect-metrics-with-prometheus/). While
it is not exposed, every meter does nothing, so you can leave the calls in your code.

## Count events

```php title="app/Actions/PlaceOrder.php"
<?php

namespace App\Actions;

use App\Models\Order;
use Nwidart\Actuator\Metrics\MeterRegistry;

final class PlaceOrder
{
    public function __construct(private MeterRegistry $metrics) {}

    public function handle(Order $order): void
    {
        // ...

        $this->metrics->counter('orders.placed', ['channel' => $order->channel])->increment();
    }
}
```

```text
# TYPE orders_placed_total counter
orders_placed_total{channel="web"} 12
```

`increment()` takes an amount: `->increment($order->lines->count())`. A counter cannot go down.

## Time an operation

```php
$receipt = $this->metrics
    ->timer('payment.charge', ['provider' => 'stripe'])
    ->time(fn () => $gateway->charge($order));
```

`time()` returns the result of the callback and records the duration, also when the callback
throws. When you already have a duration, record it in seconds:

```php
$this->metrics->timer('payment.charge', ['provider' => 'stripe'])->record(0.153);
```

```text
# TYPE payment_charge_seconds summary
payment_charge_seconds_count{provider="stripe"} 2
payment_charge_seconds_sum{provider="stripe"} 0.31
```

## Record an amount

A distribution summary counts events and adds up an amount that is not a duration:

```php
$this->metrics->summary('upload.size', ['disk' => 's3'], unit: 'bytes')->record($file->getSize());
```

```text
# TYPE upload_size_bytes summary
upload_size_bytes_count{disk="s3"} 4
upload_size_bytes_sum{disk="s3"} 1048576
```

## Publish a gauge

A gauge is a value that goes up and down. There are two ways to give it a value.

**Read it during the scrape.** Register a callback in a service provider. The callback runs when
the endpoint is read, in the process that answers:

```php title="app/Providers/AppServiceProvider.php"
use Illuminate\Support\Facades\Queue;
use Nwidart\Actuator\Metrics\MeterRegistry;

public function boot(): void
{
    $this->callAfterResolving(MeterRegistry::class, function (MeterRegistry $metrics): void {
        $metrics->gauge('queue.size', ['queue' => 'default'], fn () => Queue::size('default'));
    });
}
```

A callback that returns `null` leaves the gauge out.

**Set it when it changes.** The value is stored, and the last one wins:

```php
$this->metrics->gauge('backup.last.success', unit: 'seconds')->set(time());
```

`counter()` also takes a callback, for a total that another system already keeps.

## Add histogram buckets

A timer or summary publishes a count and a sum. Add buckets to compute percentiles in Prometheus.
The bounds are in the base unit: seconds for a timer.

```php title="config/actuator.php"
'metrics' => [
    'distribution' => [
        'slo' => [
            // ...
            'payment.charge' => [0.1, 0.25, 0.5, 1, 2.5, 5],
        ],
    ],
],
```

```text
# TYPE payment_charge_seconds histogram
payment_charge_seconds_bucket{provider="stripe",le="0.1"} 0
payment_charge_seconds_bucket{provider="stripe",le="0.25"} 2
...
payment_charge_seconds_bucket{provider="stripe",le="+Inf"} 2
```

## Add a help text

Describe the meter in a service provider, so that every process knows the text:

```php
$this->callAfterResolving(MeterRegistry::class, function (MeterRegistry $metrics): void {
    $metrics->describe('orders.placed', 'Orders placed, by sales channel.');
});
```

```text
# HELP orders_placed_total Orders placed, by sales channel.
```

## Keep the number of series small

Each combination of tag values is one series, stored and scraped forever. Use tags with a small,
fixed set of values: a channel, a provider, a status. Never use a user id, an email address, a raw
URL or an error message as a tag value.

## Record metrics from a package

A package records metrics the same way. Use `callAfterResolving()` for the calls in a service
provider: the package then also works in an application where metrics are not collected.

To add a tag to the meters the package does not own, see
[add tags to the built-in metrics](/laravel-actuator/guides/tag-built-in-metrics/).

## Test your metrics

The cache store works with the `array` cache driver, within one test. Flush the measurements,
then read the endpoint:

```php
use Nwidart\Actuator\Metrics\MeterRegistry;

public function test_an_order_is_counted(): void
{
    app(PlaceOrder::class)->handle($order);

    app(MeterRegistry::class)->flush();

    $this->get('/actuator/prometheus')->assertSee('orders_placed_total{channel="web"} 1', false);
}
```

The `prometheus` endpoint must be exposed in the test environment. An HTTP test flushes at the end
of each request, so a meter recorded by a route needs no `flush()`.
