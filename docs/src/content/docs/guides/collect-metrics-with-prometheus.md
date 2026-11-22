---
title: Collect metrics with Prometheus
description: Expose the prometheus endpoint, point a Prometheus server at it and protect it.
---

The `prometheus` endpoint returns the metrics of your application in the format a
[Prometheus](https://prometheus.io) server scrapes. The package records requests, queued jobs,
database queries, exceptions and scheduled tasks for you.

## Expose the endpoint

Add `prometheus` to the exposed endpoints:

```php title="config/actuator.php"
'exposure' => [
    'include' => ['health', 'prometheus'],
    'exclude' => [],
],
```

Metrics are collected from this moment on. While the endpoint is not exposed, nothing is recorded
and your application pays nothing.

Send a few requests to your application, then read the endpoint:

```bash
curl http://localhost/actuator/prometheus
```

```text
# HELP http_server_requests_seconds Duration of HTTP server request handling.
# TYPE http_server_requests_seconds histogram
http_server_requests_seconds_bucket{exception="none",method="GET",outcome="SUCCESS",status="200",uri="/users/{user}",le="0.005"} 0
http_server_requests_seconds_bucket{exception="none",method="GET",outcome="SUCCESS",status="200",uri="/users/{user}",le="0.01"} 3
...
http_server_requests_seconds_count{exception="none",method="GET",outcome="SUCCESS",status="200",uri="/users/{user}"} 3
http_server_requests_seconds_sum{exception="none",method="GET",outcome="SUCCESS",status="200",uri="/users/{user}"} 0.0241
# HELP php_info PHP version and server API.
# TYPE php_info gauge
php_info{sapi="fpm-fcgi",version="8.4.27"} 1
```

See the [metrics reference](/laravel-actuator/reference/metrics/) for every meter.

## Check the store

Metrics live in a store between requests. The default store is your default cache store, which
needs no setup. It suits development and small applications.

For production, or when the numbers stay at zero, read
[choose a metrics store](/laravel-actuator/guides/choose-a-metrics-store/).

## Configure Prometheus

```yaml title="prometheus.yml"
scrape_configs:
  - job_name: laravel
    metrics_path: /actuator/prometheus
    static_configs:
      - targets: ['app.example.com:80']
```

With several application servers, the target depends on the store. See
[several servers](/laravel-actuator/guides/choose-a-metrics-store/#several-servers).

## Protect the endpoint

The output names your routes, job classes and exception classes. Protect the endpoint before you
expose it on a public address:

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

Common tags are added to every meter. Use them to tell applications or environments apart:

```php title="config/actuator.php"
'metrics' => [
    'tags' => ['application' => 'shop', 'environment' => env('APP_ENV')],
],
```

## Turn meters off

Turn a meter off by its name, or a group of meters by a name prefix:

```php title="config/actuator.php"
'metrics' => [
    'enable' => [
        'db.queries' => false, // one meter
        'php' => false,        // php.info, php.opcache.*, php.fpm.*
    ],
],
```

A built-in meter that is off registers no listener. To stop all collection while the endpoint stays
exposed, set `ACTUATOR_METRICS_ENABLED=false`.

## Useful queries

```text
# Requests per second, by route
sum by (uri) (rate(http_server_requests_seconds_count[5m]))

# 95th percentile of the request duration
histogram_quantile(0.95, sum by (le) (rate(http_server_requests_seconds_bucket[5m])))

# Share of requests that fail with a server error
sum(rate(http_server_requests_seconds_count{outcome="SERVER_ERROR"}[5m]))
  / sum(rate(http_server_requests_seconds_count[5m]))

# Average duration of a queued job, by job class
sum by (job) (rate(queue_jobs_seconds_sum[5m])) / sum by (job) (rate(queue_jobs_seconds_count[5m]))
```
