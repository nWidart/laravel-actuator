---
title: Add tags to the built-in metrics
description: Add your own tag, such as a module or a tenant, to the request, query, job, cache, exception and scheduler metrics.
---

The built-in metrics carry a fixed set of attributes. `tagUsing()` adds yours. A package can call it
from its own service provider, so the application does not edit any configuration.

`tagUsing()` is [`Metrics::attributes()`](https://github.com/nWidart/laravel-metrics#add-attributes-to-the-built-in-metrics)
of laravel-metrics, which records the built-in metrics. Call either one.

## Add a tag

Register a callback for a metric, by its OpenTelemetry name. The callback receives the object the
measurement is about, and returns the tags to add:

```php title="app/Providers/AppServiceProvider.php"
use Illuminate\Foundation\Http\Events\RequestHandled;
use Nwidart\Actuator\Metrics\MeterRegistry;

public function boot(MeterRegistry $metrics): void
{
    $metrics->tagUsing('http.server.request.duration', fn (RequestHandled $event): array => [
        'tenant' => $event->request->route('tenant')?->plan ?? 'none',
    ]);
}
```

```text
http_server_request_duration_seconds_count{http_request_method="GET",http_response_status_code="200",http_route="/{tenant}/dashboard",network_protocol_version="1.1",otel_scope_name="nwidart/laravel-metrics",tenant="pro",url_scheme="https"} 4
```

Register the callback in `boot()`, on every request. `callAfterResolving(MeterRegistry::class, ...)`
works too: the package builds the registry on every request.

## What each callback receives

| Metric                            | The callback receives                                                       |
| --------------------------------- | --------------------------------------------------------------------------- |
| `http.server.request.duration`    | `Illuminate\Foundation\Http\Events\RequestHandled`                          |
| `db.client.operation.duration`    | `Illuminate\Database\Events\QueryExecuted`                                  |
| `messaging.process.duration`      | `JobProcessed`, `JobExceptionOccurred` or `JobTimedOut`                     |
| `messaging.client.sent.messages`  | `JobQueued`                                                                 |
| `laravel.cache.operations`        | The `Illuminate\Cache\Events\CacheEvent`                                    |
| `laravel.scheduler.task.duration` | `ScheduledTaskFinished` or `ScheduledTaskFailed`                            |
| `laravel.exceptions`              | The `Throwable`                                                             |

The job events are in `Illuminate\Queue\Events`, and the scheduler events in
`Illuminate\Console\Events`.

## Example: a module tag from a package

A package that splits an application into modules adds a `module` tag to four metrics. It calls
`Metrics::attributes()` of laravel-metrics, so it does not need Laravel Actuator:

```php title="src/ModulesServiceProvider.php"
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;
use Nwidart\Metrics\Facades\Metrics;
use Throwable;

public function boot(): void
{
    if (! class_exists(Metrics::class)) {
        return;
    }

    Metrics::attributes('http.server.request.duration', fn (RequestHandled $event): array => [
        'module' => $this->modules->ofRoute($event->request->route()) ?? 'none',
    ]);

    Metrics::attributes('messaging.process.duration', fn (JobProcessed|JobExceptionOccurred|JobTimedOut $event): array => [
        'module' => $this->modules->ofClass($event->job->resolveName()) ?? 'none',
    ]);

    Metrics::attributes('db.client.operation.duration', fn (QueryExecuted $event): array => [
        'module' => $this->modules->current() ?? 'none',
    ]);

    Metrics::attributes('laravel.exceptions', fn (Throwable $e): array => [
        'module' => $this->modules->ofFile($e->getFile()) ?? 'none',
    ]);
}
```

## Rules for a tag

- **Always return the same tag names.** Return `'none'`, not an empty array, when there is no
  value. Series of one metric should carry the same tags.
- **Keep the set of values small and fixed.** Each value multiplies the number of series. A module,
  a tenant plan or a region is fine. A user id is not.
- **Be fast.** The callback runs inside the work it describes, for every request, job and query. A
  measurement that the callback causes itself, such as a query, gets no contributed tags.
- **Do not rely on exceptions.** A callback that throws is skipped, and its exception is reported
  once.
- **Return strings, numbers or booleans.** A value of another type, such as `null`, is dropped.

A tag you return replaces a built-in attribute of the same name. Use this to bound an attribute
that your application makes unbounded, such as the queue name when queues are created at runtime:

```php
$metrics->tagUsing('messaging.process.duration', fn (): array => [
    'messaging.destination.name' => 'tenant',
]);
```

## Tag your own meters

Your own meters take their tags when you create them. To let others tag a meter you record, ask the
registry for the contributed tags:

```php
$this->metrics->timer('search.queries', [
    'index' => $index,
    ...$this->metrics->tagsFor('search.queries', $query),
])->record($seconds);
```
