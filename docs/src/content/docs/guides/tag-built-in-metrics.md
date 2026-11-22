---
title: Add tags to the built-in metrics
description: Add your own tag, such as a module or a tenant, to the request, job, query, exception and scheduler metrics.
---

The built-in meters carry a fixed set of tags. `tagUsing()` adds yours. A package can call it from
its own service provider, so the application does not edit any configuration.

## Add a tag

Register a callback for a meter. The callback receives the object the measurement is about, and
returns the tags to add:

```php title="app/Providers/AppServiceProvider.php"
use Illuminate\Foundation\Http\Events\RequestHandled;
use Nwidart\Actuator\Metrics\MeterRegistry;

public function boot(): void
{
    $this->callAfterResolving(MeterRegistry::class, function (MeterRegistry $metrics): void {
        $metrics->tagUsing('http.server.requests', fn (RequestHandled $event): array => [
            'tenant' => $event->request->route('tenant')?->plan ?? 'none',
        ]);
    });
}
```

```text
http_server_requests_seconds_count{exception="none",method="GET",outcome="SUCCESS",status="200",tenant="pro",uri="/{tenant}/dashboard"} 4
```

`callAfterResolving()` runs the callback when the registry is built. While metrics are not
collected, the registry is never built and your callback never runs.

## What each callback receives

| Meter                       | The callback receives                                                         |
| --------------------------- | ----------------------------------------------------------------------------- |
| `http.server.requests`      | `Illuminate\Foundation\Http\Events\RequestHandled`                            |
| `queue.jobs`                | `Illuminate\Queue\Events\JobProcessed`, or `JobExceptionOccurred` when the job threw |
| `db.queries`                | `Illuminate\Database\Events\QueryExecuted`                                    |
| `exceptions`                | The `Throwable`                                                               |
| `tasks.scheduled.execution` | `Illuminate\Console\Events\ScheduledTaskFinished`, or `ScheduledTaskFailed` when the task threw |

## Example: a module tag from a package

A package that splits an application into modules adds a `module` tag to four meters:

```php title="src/ModulesServiceProvider.php"
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Throwable;

public function boot(): void
{
    $this->callAfterResolving(MeterRegistry::class, function (MeterRegistry $metrics): void {
        $metrics->tagUsing('http.server.requests', fn (RequestHandled $event): array => [
            'module' => $this->modules->ofRoute($event->request->route()) ?? 'none',
        ]);

        $metrics->tagUsing('queue.jobs', fn (JobProcessed|JobExceptionOccurred $event): array => [
            'module' => $this->modules->ofClass($event->job->resolveName()) ?? 'none',
        ]);

        $metrics->tagUsing('db.queries', fn (QueryExecuted $event): array => [
            'module' => $this->modules->current() ?? 'none',
        ]);

        $metrics->tagUsing('exceptions', fn (Throwable $e): array => [
            'module' => $this->modules->ofFile($e->getFile()) ?? 'none',
        ]);
    });
}
```

The package does not need Laravel Actuator at runtime. `callAfterResolving()` takes a class name,
and the callback only runs in an application that has the registry.

## Rules for a tag

- **Always return the same tag names.** Return `'none'`, not an empty array, when there is no
  value. Series of one meter should carry the same tags.
- **Keep the set of values small and fixed.** Each value multiplies the number of series. A module,
  a tenant plan or a region is fine. A user id is not.
- **Be fast.** The callback runs for every request, job and query.
- **Do not rely on exceptions.** A callback that throws is ignored, its exception is reported once
  per process, and the measurement is recorded without its tags.
- **Return strings, numbers or booleans.** A value of another type, such as `null`, is left out.

A tag you return replaces a built-in tag of the same name. Use this to bound a built-in tag that
your application makes unbounded, such as the `queue` tag when queues are created at runtime:

```php
$metrics->tagUsing('queue.jobs', fn (JobProcessed|JobExceptionOccurred $event): array => [
    'queue' => str_starts_with($event->job->getQueue(), 'tenant-') ? 'tenant' : $event->job->getQueue(),
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
