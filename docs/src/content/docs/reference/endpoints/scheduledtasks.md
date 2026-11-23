---
title: scheduledtasks
description: The scheduledtasks endpoint, which lists the tasks of the Laravel scheduler in the Spring Boot format.
---

Lists the tasks registered in the Laravel scheduler.

| Property      | Value                                                          |
| ------------- | -------------------------------------------------------------- |
| ID            | `scheduledtasks`                                               |
| Path          | `GET /actuator/scheduledtasks`                                 |
| Route name    | `actuator.scheduledtasks`                                      |
| Exposure      | Not exposed by default                                         |
| Middleware    | `middleware`, then `scheduledtasks.middleware`                 |
| Configuration | [`scheduledtasks`](/laravel-actuator/reference/configuration/#mappings-and-scheduledtasks) |
| Class         | `Nwidart\Actuator\Http\Controllers\ScheduledTasksController`   |

The endpoint loads the console kernel first, so it sees tasks defined in `routes/console.php` and in
`withSchedule()` in `bootstrap/app.php`.

## Response

HTTP 200 with a JSON object holding four lists:

| Key          | Content                                                       |
| ------------ | ------------------------------------------------------------- |
| `cron`       | Tasks that run every minute or less often.                    |
| `fixedRate`  | Sub-minute tasks, such as `everyTenSeconds()`.                |
| `fixedDelay` | Always empty. Laravel has no equivalent.                      |
| `custom`     | Always empty. Laravel has no equivalent.                      |

### `cron` entries

| Key                  | Type   | Description                                                         |
| -------------------- | ------ | ------------------------------------------------------------------- |
| `runnable.target`    | string | What the task runs. See [targets](#targets).                        |
| `expression`         | string | Cron expression, for example `0 12 * * *`.                          |
| `timezone`           | string | Task timezone, or `app.timezone` when the task sets none.           |
| `nextExecution.time` | string | Next run, ISO 8601, in the task timezone.                           |
| `lastExecution`      | object | The last run. Left out when none is recorded. See [last execution](#last-execution). |

### `fixedRate` entries

| Key               | Type    | Description                              |
| ----------------- | ------- | ---------------------------------------- |
| `runnable.target` | string  | What the task runs.                      |
| `initialDelay`    | integer | Always `0`.                              |
| `interval`        | integer | Interval in milliseconds.                |
| `lastExecution`   | object  | The last run. Left out when none is recorded. |

### Last execution

The scheduler records each run in a cache store, and the endpoint reads it back. The shape is
Spring Boot's (3.4 and later):

| Key                         | Type   | Description                                                   |
| --------------------------- | ------ | ------------------------------------------------------------- |
| `time`                      | string | When the run started or ended, ISO 8601, in `app.timezone`.   |
| `status`                    | string | `STARTED`, `SUCCESS` or `ERROR`.                              |
| `exception.type`            | string | Only for `ERROR`: the exception class.                        |
| `exception.message`         | string | Only for `ERROR`, and only when [`show_exceptions`](#exception-messages) allows it. Cut at 1000 characters. |

| Status    | When                                                                            |
| --------- | ------------------------------------------------------------------------------- |
| `STARTED` | The task started and has not ended. A `runInBackground()` task stays `STARTED` until its process ends. |
| `SUCCESS` | The task ended without an exception, and a command exited with code `0`.        |
| `ERROR`   | The task threw an exception, or a command exited with another code. The exception of a command is `Exception` with the message `Scheduled command [...] failed with exit code [...]`. |

A task that the scheduler skips, for example because its `when()` filter fails, keeps its last
execution. A record expires after 7 days without a run, so a removed task leaves no record behind.

The scheduler and the web instances must share the store. Set it with
[`scheduler.store`](/laravel-actuator/reference/configuration/#scheduler). The `file` and `array`
stores do not work when the scheduler runs on another host. A store that fails never fails a task:
the scheduler logs a warning and goes on. The endpoint then answers without `lastExecution`.

A task is recorded under its [target](#targets). Two tasks with the same target share one record:
give them different descriptions to keep them apart.

### Exception messages

An exception message can hold a secret, such as an argument of a command. The message is shown when
`scheduledtasks.show_exceptions` allows it: `never` (the default), `always` or `when_authorized`.
See [visibility values](/laravel-actuator/reference/configuration/#visibility-values). The exception
type is always shown.

### Targets

| Task                                              | `target`                                  |
| ------------------------------------------------- | ----------------------------------------- |
| Has a `description()`                             | The description                           |
| Artisan command, for example `command('inspire')` | The command, for example `php artisan inspire` |
| Shell command, for example `exec('backup.sh')`    | The command                               |
| Closure or job without a description              | Laravel's summary, for example `Closure` or the job class |

## Example

```php title="routes/console.php"
Schedule::command('inspire')->dailyAt('12:00')->timezone('Europe/Brussels');
Schedule::call(fn () => Poller::poll())->everyTenSeconds()->description('Poll');
```

```bash
curl http://localhost/actuator/scheduledtasks
```

```json
{
  "cron": [
    {
      "runnable": { "target": "php artisan inspire" },
      "expression": "0 12 * * *",
      "timezone": "Europe/Brussels",
      "nextExecution": { "time": "2026-01-01T12:00:00+01:00" },
      "lastExecution": {
        "time": "2025-12-31T11:00:03+00:00",
        "status": "ERROR",
        "exception": { "type": "Exception" }
      }
    }
  ],
  "fixedDelay": [],
  "fixedRate": [
    {
      "runnable": { "target": "Poll" },
      "initialDelay": 0,
      "interval": 10000,
      "lastExecution": { "time": "2026-01-01T10:29:50+00:00", "status": "SUCCESS" }
    }
  ],
  "custom": []
}
```
