---
title: prometheus
description: The prometheus endpoint, which returns every meter in the Prometheus text exposition format.
---

Returns the metrics of the application for a Prometheus server.

| Property      | Value                                                          |
| ------------- | -------------------------------------------------------------- |
| ID            | `prometheus`                                                   |
| Path          | `GET /actuator/prometheus`                                     |
| Route name    | `actuator.prometheus`                                          |
| Exposure      | Not exposed by default                                         |
| Middleware    | `middleware`, then `prometheus.middleware`                     |
| Class         | `Nwidart\Actuator\Http\Controllers\PrometheusController`       |

Metrics are only collected while this endpoint is exposed and `metrics.enabled` is `true`.

## Response

| Case                              | Status | Body                                              |
| --------------------------------- | ------ | ------------------------------------------------- |
| The meters were read              | 200    | The meters, in the text exposition format         |
| `metrics.enabled` is `false`      | 200    | Empty                                             |
| The metrics store cannot be read  | 503    | A comment line. The exception is reported.        |

The content type is `text/plain; version=0.0.4; charset=utf-8`. The OpenMetrics format is not
offered.

Requests to the actuator endpoints are not counted in `http.server.requests`.

## Format

Each meter name becomes a metric family. Families are sorted by name.

```text
# HELP http_server_requests_seconds Duration of HTTP server request handling.
# TYPE http_server_requests_seconds histogram
http_server_requests_seconds_bucket{method="GET",uri="/users/{user}",le="0.005"} 0
http_server_requests_seconds_bucket{method="GET",uri="/users/{user}",le="+Inf"} 3
http_server_requests_seconds_count{method="GET",uri="/users/{user}"} 3
http_server_requests_seconds_sum{method="GET",uri="/users/{user}"} 0.0241
# HELP exceptions_total Exceptions reported by the application.
# TYPE exceptions_total counter
exceptions_total{exception="RuntimeException"} 2
```

A `# HELP` line is present when the meter has a description.

### Names

Names follow the Prometheus naming convention of Micrometer, as Spring Boot does.

| Meter type            | Rule                                               | Example                                               |
| --------------------- | -------------------------------------------------- | ----------------------------------------------------- |
| Any                   | Characters other than letters, digits and `_` become `_` | `http.server.requests` → `http_server_requests` |
| Counter               | The unit, then `_total`, are appended              | `orders.placed` → `orders_placed_total`               |
| Gauge                 | The unit is appended                               | `disk.free` (bytes) → `disk_free_bytes`               |
| Timer                 | `_seconds` is appended                             | `queue.jobs` → `queue_jobs_seconds`                   |
| Distribution summary  | The unit is appended                               | `upload.size` (bytes) → `upload_size_bytes`           |

A unit or `_total` that the name already ends with is not repeated. Tag names follow the first
rule.

### Types

| Meter                                     | `# TYPE`    | Samples                                           |
| ----------------------------------------- | ----------- | ------------------------------------------------- |
| Counter                                   | `counter`   | One value                                         |
| Gauge                                     | `gauge`     | One value                                         |
| Timer or summary with buckets             | `histogram` | `_bucket` for each bound and `+Inf`, `_count`, `_sum` |
| Timer or summary without buckets          | `summary`   | `_count`, `_sum`                                  |

Bucket counts are cumulative. Quantiles and maximum values are not published: see
[what is left out](/laravel-actuator/concepts/metrics-model/#what-is-left-out).

### Escaping

In a tag value, `\`, `"` and a line feed are written as `\\`, `\"` and `\n`. A job class therefore
reads `job="App\\Jobs\\SendInvoice"`.

## Related

- [Built-in meters](/laravel-actuator/reference/metrics/)
- [Configuration of `metrics`](/laravel-actuator/reference/configuration/#metrics)
- [Collect metrics with Prometheus](/laravel-actuator/guides/collect-metrics-with-prometheus/)
