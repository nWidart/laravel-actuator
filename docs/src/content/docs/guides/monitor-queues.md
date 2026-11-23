---
title: Monitor queues
description: Report queue backlog, failed jobs and Horizon status in a health group of their own, outside the Kubernetes probes.
---

Three indicators report on queues. They are off by default:

- `queue` reports the size of each queue. See [`QueueHealthIndicator`](/laravel-actuator/reference/health-indicators/#queuehealthindicator).
- `failedJobs` counts the failed jobs. See [`FailedJobsHealthIndicator`](/laravel-actuator/reference/health-indicators/#failedjobshealthindicator).
- `horizon` reports whether Horizon runs. See [`HorizonHealthIndicator`](/laravel-actuator/reference/health-indicators/#horizonhealthindicator).

## Turn the indicators on

Add them to `health.indicators`, with the thresholds that fit your traffic:

```php title="config/actuator.php"
use Nwidart\Actuator\Health\Indicators\FailedJobsHealthIndicator;
use Nwidart\Actuator\Health\Indicators\HorizonHealthIndicator;
use Nwidart\Actuator\Health\Indicators\QueueHealthIndicator;

'indicators' => [
    // ...
    'queue' => [QueueHealthIndicator::class, ['warningThreshold' => 100, 'downThreshold' => 1000]],
    'failedJobs' => [FailedJobsHealthIndicator::class, ['downThreshold' => 50, 'minutes' => 60]],
    'horizon' => HorizonHealthIndicator::class,
],
```

Leave out `horizon` if you do not run Horizon: without `laravel/horizon`, it reports `UNKNOWN`.

## Choose the queues

The `queue` indicator watches the default queue of the default connection. List other queues in
`queue.queues`, by connection:

```php title="config/actuator.php"
'queue' => [
    'queues' => [
        'redis' => ['default', 'emails'],
        'sqs' => ['reports'],
    ],
],
```

Each queue gets its own counts in the details:

```json
"queue": {
  "status": "UP",
  "details": {
    "queues": {
      "redis": {
        "default": { "size": 12, "pending": 10, "delayed": 1, "reserved": 1 },
        "emails": { "size": 0, "pending": 0, "delayed": 0, "reserved": 0 }
      },
      "sqs": { "reports": { "size": 3, "pending": 3, "delayed": 0, "reserved": 0 } }
    },
    "warningThreshold": 100,
    "downThreshold": 1000
  }
}
```

`pending`, `delayed` and `reserved` need Laravel 12.20 or later. Before, and with a driver that
cannot count them, the details show only `size`.

## Set the thresholds

A queue at or over `warningThreshold` makes the indicator `WARNING`. A queue at or over
`downThreshold` makes it `DOWN`. `null` turns a threshold off. The largest queue decides.

`failedJobs` is `WARNING` from one failed job. Set `minutes` to count only the recent failures:
without it, an old failure keeps the indicator `WARNING` until you retry or delete the job. Only the
`database` and `database-uuids` failers count within a window.

## Put them in a group of their own

Add a `queues` group, and point your alerting or dashboard at `/actuator/health/queues`:

```php title="config/actuator.php"
'groups' => [
    'liveness' => ['include' => ['ping']],
    'readiness' => ['include' => ['readinessState', 'migrations']],
    'queues' => ['include' => ['queue', 'failedJobs', 'horizon']],
],
```

:::caution
Keep the queue indicators out of `liveness` and `readiness`. Restarting web pods does not drain a
queue, and taking them out of rotation does not either. A backlog is shared by every instance, so it
would fail every probe at once. See [liveness and readiness](/laravel-actuator/concepts/liveness-and-readiness/).
:::

The indicators still count in `/actuator/health`, which aggregates every indicator. `WARNING`
answers `200`; `DOWN` answers `503`.

## Turn on the health cache

Each probe runs every indicator of the group. On the `database` queue driver, the `queue` indicator
runs a `COUNT(*)` on the jobs table for each queue, and `failedJobs` counts the failed jobs table.
Reuse the results for a few seconds:

```ini title=".env"
ACTUATOR_HEALTH_CACHE_TTL=5
```

See [`health.cache`](/laravel-actuator/reference/configuration/#healthcache).

## Export queue gauges

To graph the backlog and alert on it in Prometheus, export the watched queues as gauges of the
`prometheus` endpoint:

```ini title=".env"
ACTUATOR_QUEUE_METRICS=true
```

`laravel_queue_size` has the size of each queue of `queue.queues`, and `laravel_queue_jobs` its
pending, delayed and reserved jobs. `laravel_queue_failed_jobs` counts the failed jobs. See
[queue metrics](/laravel-actuator/reference/metrics/#queues).

Each scrape counts every queue again: the health cache does not apply. On the `database` driver,
that is a `COUNT(*)` on the jobs table for each queue and state. A queue that cannot be read loses
only its own series. A connection that hangs holds the whole scrape, and past `scrape_timeout`
every metric of the instance is missing. Keep the connect timeouts of your queue connections short.

A Prometheus alert when a queue stays above 1,000 jobs for 10 minutes:

```yaml title="alerts.yml"
groups:
  - name: laravel
    rules:
      - alert: QueueBacklog
        expr: max by (connection, queue) (laravel_queue_size) > 1000
        for: 10m
        annotations:
          summary: "{{ $labels.queue }} on {{ $labels.connection }} holds {{ $value }} jobs"
```

## Check the indicators

```bash
php artisan actuator:health queues --details
```

## See also

- [Health indicators reference](/laravel-actuator/reference/health-indicators/)
- [Define health groups](/laravel-actuator/guides/define-health-groups/)
- [Kubernetes probes](/laravel-actuator/guides/kubernetes-probes/)
