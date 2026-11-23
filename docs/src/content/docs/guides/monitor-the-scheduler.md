---
title: Monitor the scheduler
description: Record the scheduler's ticks and task runs in a shared store, turn on the scheduler indicator, and probe it from the scheduler pod or the web pods.
---

When the scheduler stops, nothing fails: tasks just stop running. The package records each tick of
`schedule:run` and the last run of each task. The `scheduler` health indicator reports `DOWN` when
the ticks stop, and the [`scheduledtasks`](/laravel-actuator/reference/endpoints/scheduledtasks/#last-execution)
endpoint shows when each task last ran and whether it failed.

## Share a store

The scheduler writes the records, and the web instances read them. Both must use the same cache
store. Set it in `.env`:

```dotenv title=".env"
ACTUATOR_SCHEDULER_STORE=redis
```

Without it, the package uses the application's default cache store.

:::caution
The `file` and `array` stores do not work when the scheduler runs on another host than the web
instances. The web instances never see a tick, so the indicator reports `DOWN` and the endpoint shows
no `lastExecution`. Use `redis`, `database` or `memcached`.
:::

Recording is on by default. A store that fails never stops a task. After the first failed write,
`schedule:run` logs a warning and writes no more records until it ends. The next `schedule:run`, a
minute later, tries again. A store that hangs until its timeout delays the tasks only once per
minute.

## Turn on the indicator

The indicator is off by default. Add it to `health.indicators`:

```php title="config/actuator.php" ins={3}
'indicators' => [
    // ...
    'scheduler' => Nwidart\Actuator\Health\Indicators\SchedulerHealthIndicator::class,
],
```

It is `WARNING` after 2 minutes without a tick, and `DOWN` after 5. `schedule:work`, or a cron entry
for `schedule:run`, ticks every minute. Change the thresholds with arguments:

```php title="config/actuator.php"
'scheduler' => [SchedulerHealthIndicator::class, ['downAfter' => 10, 'warningAfter' => null]],
```

Do not add `scheduler` to the `liveness` or `readiness` group of the web pods. A stopped scheduler
would then take every web pod out of rotation.

## Probe the scheduler pod

A pod that runs `schedule:work` has no HTTP server. Use an `exec` probe with
`php artisan actuator:health scheduler`. It exits `1` when the scheduler is `DOWN`, so Kubernetes
restarts the pod:

```yaml title="scheduler-deployment.yaml"
spec:
  containers:
    - name: scheduler
      image: my-app:1.4.2
      args: ["php", "artisan", "schedule:work"]
      livenessProbe:
        exec:
          command: ["php", "artisan", "actuator:health", "scheduler"]
        initialDelaySeconds: 90
        periodSeconds: 60
        timeoutSeconds: 10
        failureThreshold: 3
```

`initialDelaySeconds` gives a new pod time for its first tick. The last tick of a pod that stopped
long ago would otherwise make it `DOWN`.

## Check from the web pods

Put the indicator in a group of its own, and point your monitoring at it:

```php title="config/actuator.php"
'groups' => [
    'liveness' => ['include' => ['ping']],
    'readiness' => ['include' => ['readinessState', 'migrations']],
    'scheduling' => ['include' => ['scheduler']],
],
```

`/actuator/health/scheduling` answers HTTP 503 when the scheduler is `DOWN`, and HTTP 200 when it is
`UP` or `WARNING`. See [define health groups](/laravel-actuator/guides/define-health-groups/).

The tick is shared. When several hosts run `schedule:run`, the indicator reports that one of them
ticks, not which one.

## Read the last runs

Expose the `scheduledtasks` endpoint and read `lastExecution`:

```bash
curl http://localhost/actuator/scheduledtasks
```

```json
{
  "runnable": { "target": "php artisan reports:send" },
  "expression": "0 * * * *",
  "timezone": "UTC",
  "nextExecution": { "time": "2026-01-01T11:00:00+00:00" },
  "lastExecution": {
    "time": "2026-01-01T10:00:04+00:00",
    "status": "ERROR",
    "exception": { "type": "Exception" }
  }
}
```

The exception message is hidden by default, because it can hold a command's arguments. Set
`scheduledtasks.show_exceptions` to `when_authorized` or `always` to show it.

## Turn recording off

```dotenv title=".env"
ACTUATOR_SCHEDULER_RECORD=false
```

The endpoint then leaves out `lastExecution`, and the indicator reports `UNKNOWN`.
