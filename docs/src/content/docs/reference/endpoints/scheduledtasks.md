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

### `fixedRate` entries

| Key               | Type    | Description                              |
| ----------------- | ------- | ---------------------------------------- |
| `runnable.target` | string  | What the task runs.                      |
| `initialDelay`    | integer | Always `0`.                              |
| `interval`        | integer | Interval in milliseconds.                |

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
      "nextExecution": { "time": "2026-01-01T12:00:00+01:00" }
    }
  ],
  "fixedDelay": [],
  "fixedRate": [
    { "runnable": { "target": "Poll" }, "initialDelay": 0, "interval": 10000 }
  ],
  "custom": []
}
```
