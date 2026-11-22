---
title: Record your own metrics
description: Count events, time operations and publish gauges from your application or your package.
---

Inject `Nwidart\Actuator\Metrics\MeterRegistry` and create a meter. A meter has a dotted name and
optional tags. The [metrics model](/laravel-actuator/concepts/metrics-model/) explains each type.

The meters are recorded by [nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics#readme),
into its store, whether or not the `prometheus` endpoint is exposed. To read them, see
[collect metrics with Prometheus](/laravel-actuator/guides/collect-metrics-with-prometheus/). With
`METRICS_ENABLED=false`, every meter does nothing, so you can leave the calls in your code.

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
orders_placed_total{channel="web",otel_scope_name="nwidart/laravel-actuator"} 12
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

A timer is a Prometheus histogram. Without [buckets](#add-histogram-buckets), it has only the `+Inf`
bucket, which holds the count:

```text
# TYPE payment_charge_seconds histogram
payment_charge_seconds_bucket{otel_scope_name="nwidart/laravel-actuator",provider="stripe",le="+Inf"} 2
payment_charge_seconds_sum{otel_scope_name="nwidart/laravel-actuator",provider="stripe"} 0.31
payment_charge_seconds_count{otel_scope_name="nwidart/laravel-actuator",provider="stripe"} 2
```

## Record an amount

A distribution summary counts events and adds up an amount that is not a duration:

```php
$this->metrics->summary('upload.size', ['disk' => 's3'], unit: 'bytes')->record($file->getSize());
```

```text
# TYPE upload_size_bytes histogram
upload_size_bytes_bucket{disk="s3",otel_scope_name="nwidart/laravel-actuator",le="+Inf"} 4
upload_size_bytes_sum{disk="s3",otel_scope_name="nwidart/laravel-actuator"} 1048576
upload_size_bytes_count{disk="s3",otel_scope_name="nwidart/laravel-actuator"} 4
```

The units `seconds`, `milliseconds` and `bytes` become `_seconds`, `_milliseconds` and `_bytes`.
Another unit is passed on as it is.

## Publish a gauge

A gauge is a value that goes up and down. There are two ways to give it a value.

**Read it during the scrape.** Register a callback in the `boot()` method of a service provider.
The callback runs when the endpoint is read, in the process that answers. Its value is never
stored:

```php title="app/Providers/AppServiceProvider.php"
use Illuminate\Support\Facades\Queue;
use Nwidart\Actuator\Metrics\MeterRegistry;

public function boot(MeterRegistry $metrics): void
{
    $metrics->gauge('queue.size', ['queue' => 'default'], fn () => Queue::size('default'));
}
```

A callback that returns `null` leaves the gauge out. A callback that throws is skipped, and its
exception is reported once.

Register the callback on every request, as above, or with
`$this->callAfterResolving(MeterRegistry::class, ...)`: the package builds the registry on every
request, so both run in the request that answers the scrape.

**Set it when it changes.** The value is stored, and the last value set wins, across every
process:

```php
$this->metrics->gauge('backup.last.success', unit: 'seconds')->set(time());
```

`counter()` also takes a callback, for a total that another system already keeps. It is read
during the scrape in the same way, and `increment()` then does nothing.

## Add histogram buckets

A timer or summary publishes a count and a sum. Add buckets to compute percentiles in Prometheus.
The bounds are in the base unit: seconds for a timer.

```php title="config/actuator.php"
'metrics' => [
    'distribution' => [
        'slo' => [
            'payment.charge' => [0.1, 0.25, 0.5, 1, 2.5, 5],
        ],
    ],
],
```

```text
# TYPE payment_charge_seconds histogram
payment_charge_seconds_bucket{otel_scope_name="nwidart/laravel-actuator",provider="stripe",le="0.1"} 0
payment_charge_seconds_bucket{otel_scope_name="nwidart/laravel-actuator",provider="stripe",le="0.25"} 2
...
payment_charge_seconds_bucket{otel_scope_name="nwidart/laravel-actuator",provider="stripe",le="+Inf"} 2
```

`metrics.distribution.slo` applies only to the meters you create with `MeterRegistry`. The built-in
metrics have buckets of their own.

## Add a help text

Describe the meter in a service provider, before it is first used:

```php
public function boot(MeterRegistry $metrics): void
{
    $metrics->describe('orders.placed', 'Orders placed, by sales channel.');
}
```

```text
# HELP orders_placed_total Orders placed, by sales channel.
```

## Keep the number of series small

Each combination of tag values is one series, stored and scraped forever. Use tags with a small,
fixed set of values: a channel, a provider, a status. Never use a user id, an email address, a raw
URL or an error message as a tag value.

## Record metrics from a package

A package records metrics the same way. When Laravel Actuator is optional for the package, guard
the calls in its service provider:

```php title="src/BillingServiceProvider.php"
use Nwidart\Actuator\Metrics\MeterRegistry;

public function boot(): void
{
    if (class_exists(MeterRegistry::class)) {
        $this->app->make(MeterRegistry::class)->describe('billing.invoices', 'Invoices sent.');
    }
}
```

To add a tag to the metrics the package does not own, see
[add tags to the built-in metrics](/laravel-actuator/guides/tag-built-in-metrics/).

## Test your metrics

`Metrics::fake()` of laravel-metrics keeps what the application records in memory, so a test needs
no Redis and sees only its own metrics. Call it before the code under test records:

```php
use Nwidart\Metrics\Facades\Metrics;

public function test_an_order_is_counted(): void
{
    $metrics = Metrics::fake();

    app(PlaceOrder::class)->handle($order);

    $metrics->assertValue('orders.placed', 1, ['channel' => 'web']);
}
```

`assertRecorded()`, `assertNotRecorded()` and `assertNothingRecorded()` are also available. See
[testing](https://github.com/nWidart/laravel-metrics#testing) in the laravel-metrics README.

A meter with a callback is not recorded, so the fake does not hold it. To test one, expose
`prometheus` and read the endpoint. Call `Metrics::fake()` first: the endpoint then reads the fake
store. Without it, the endpoint reads the real store, and answers HTTP 503 when the test
environment has no Redis:

```php
Metrics::fake();

$this->get('/actuator/prometheus')
    ->assertOk()
    ->assertSee('queue_size{otel_scope_name="nwidart/laravel-actuator",queue="default"} 0', false);
```
