---
title: Collect metrics with Prometheus
description: Expose the prometheus endpoint, point a Prometheus server at it and protect it.
---

The `prometheus` endpoint returns the metrics of your application in the format a
[Prometheus](https://prometheus.io) server scrapes. The metrics are recorded by
[nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics#readme), which Laravel Actuator
installs: requests, database queries, queued jobs, cache operations, scheduled tasks and
exceptions, with no code.

## Check the store

laravel-metrics records from the moment it is installed, whether or not the endpoint is exposed.
Each request, job and command writes what it recorded to a store, once, when it ends. The default
store is the `default` Redis connection.

Without Redis, the measurements are dropped, and a warning goes to the PHP error log. For
development without Redis, use a Laravel cache store:

```ini title=".env"
METRICS_STORE=cache
```

See [choose a metrics store](/laravel-actuator/guides/choose-a-metrics-store/). To turn the recording
off, set `METRICS_ENABLED=false`.

## Expose the endpoint

Add `prometheus` to the exposed endpoints:

```php title="config/actuator.php"
'exposure' => [
    'include' => ['health', 'prometheus'],
    'exclude' => [],
],
```

Send a few requests to your application, then read the endpoint:

```bash
curl http://localhost/actuator/prometheus
```

```text
# HELP http_server_request_duration_seconds Duration of HTTP server requests.
# TYPE http_server_request_duration_seconds histogram
http_server_request_duration_seconds_bucket{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",network_protocol_version="1.1",otel_scope_name="nwidart/laravel-metrics",url_scheme="https",le="0.005"} 0
http_server_request_duration_seconds_bucket{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",network_protocol_version="1.1",otel_scope_name="nwidart/laravel-metrics",url_scheme="https",le="0.01"} 3
...
http_server_request_duration_seconds_sum{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",network_protocol_version="1.1",otel_scope_name="nwidart/laravel-metrics",url_scheme="https"} 0.0241
http_server_request_duration_seconds_count{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",network_protocol_version="1.1",otel_scope_name="nwidart/laravel-metrics",url_scheme="https"} 3
# HELP laravel_info Laravel and PHP versions of the process that exports the metrics.
# TYPE laravel_info gauge
laravel_info{laravel_version="13.43.0",otel_scope_name="nwidart/laravel-metrics",php_sapi="fpm-fcgi",process_runtime_name="php",process_runtime_version="8.4.27"} 1
```

Requests to the actuator endpoints are left out of `http.server.request.duration`, so probes and
scrapes do not drown your own traffic. The queries and cache operations of the health indicators
are still recorded. See the [metrics reference](/laravel-actuator/reference/metrics/) for every
metric.

## Configure Prometheus

```yaml title="prometheus.yml"
scrape_configs:
  - job_name: laravel
    metrics_path: /actuator/prometheus
    static_configs:
      - targets: ['app.example.com:80']
```

Every server that uses the same store returns the totals of the whole application. Scrape one
address, such as the load balancer, not each server.

## Protect the endpoint

The output names your routes, databases, cache stores, job classes and exception classes. Protect
the endpoint before you expose it on a public address:

```php title="config/actuator.php"
'prometheus' => [
    'middleware' => ['auth.basic'],
],
```

```yaml title="prometheus.yml"
scrape_configs:
  - job_name: laravel
    metrics_path: /actuator/prometheus
    basic_auth:
      username: prometheus@example.com
      password_file: /etc/prometheus/laravel.password
    static_configs:
      - targets: ['app.example.com:80']
```

Any middleware works, see [secure the endpoints](/laravel-actuator/guides/secure-endpoints/).

## Label every metric with the application

Labels in `prometheus.labels` of `config/metrics.php` are added to every sample. Use them to tell
applications or environments apart:

```php title="config/metrics.php"
'prometheus' => [
    // ...
    'labels' => ['application' => 'shop', 'environment' => env('APP_ENV')],
],
```

Publish the file first with `php artisan vendor:publish --tag=metrics-config`.

## Turn metrics off

Turn a group of built-in metrics off in `config/metrics.php`, or with its environment variable:

```ini title=".env"
METRICS_DB=false
METRICS_RUNTIME=false
```

| Variable             | Turns off                                                        |
| -------------------- | ---------------------------------------------------------------- |
| `METRICS_HTTP`       | `http.server.request.duration`                                   |
| `METRICS_DB`         | `db.client.operation.duration`, `laravel.db.transaction.duration`, `laravel.db.queries` |
| `METRICS_ELOQUENT`   | `laravel.eloquent.models`, `laravel.eloquent.models.pruned`      |
| `METRICS_QUEUE`      | `messaging.process.duration`, `messaging.client.sent.messages`   |
| `METRICS_CACHE`      | `laravel.cache.operations`                                       |
| `METRICS_SCHEDULER`  | `laravel.scheduler.task.duration`                                |
| `METRICS_EXCEPTIONS` | `laravel.exceptions`                                             |
| `METRICS_RUNTIME`    | `laravel.info`, `php.opcache.*`, `php.fpm.*`, `system.*`         |
| `METRICS_DB_SERVER`  | `laravel.db.server.connections`, `laravel.db.server.connections.max` |
| `METRICS_MIGRATIONS` | `laravel.db.migrations.pending`                                  |

To leave more routes out of `http.server.request.duration`, add them to `ignore_routes` in
`config/metrics.php`. A pattern that starts with `/` matches the URI template, such as `/up`. Any
other pattern matches the route name, such as `horizon.*`.

Your own meters are turned off with
[`metrics.enable`](/laravel-actuator/reference/configuration/#metricsenable) in
`config/actuator.php`.

## Useful queries

```text
# Requests per second, by route
sum by (http_route) (rate(http_server_request_duration_seconds_count[5m]))

# 95th percentile of the request duration
histogram_quantile(0.95, sum by (le) (rate(http_server_request_duration_seconds_bucket[5m])))

# Share of requests that fail with a server error
sum(rate(http_server_request_duration_seconds_count{error_type!=""}[5m]))
  / sum(rate(http_server_request_duration_seconds_count[5m]))

# Average duration of a queued job, by job class
sum by (laravel_job_name) (rate(messaging_process_duration_seconds_sum[5m]))
  / sum by (laravel_job_name) (rate(messaging_process_duration_seconds_count[5m]))
```

## Alert on health

The [`laravel_health_status`](/laravel-actuator/reference/metrics/#health-status) gauge is 1 for the
current status of each health indicator. Alert on it instead of scraping `/actuator/health`:

```yaml title="alerts.yml"
groups:
  - name: laravel
    rules:
      - alert: HealthIndicatorDown
        expr: laravel_health_status{status="DOWN"} == 1
        for: 2m
        annotations:
          summary: "{{ $labels.component }} is DOWN on {{ $labels.instance }}"
      - alert: ApplicationUnavailable
        expr: laravel_health_overall_status{status=~"DOWN|OUT_OF_SERVICE"} == 1
        for: 5m
      - alert: HealthMetricsMissing
        expr: absent(laravel_health_overall_status)
        for: 5m
```

Each scrape runs the indicators, unless the [health cache](/laravel-actuator/reference/configuration/#healthcache)
holds their results. Turn it on.

For a ready-made dashboard with these queries and more, see
[use the sample Grafana dashboard](/laravel-actuator/guides/grafana-dashboard/).
