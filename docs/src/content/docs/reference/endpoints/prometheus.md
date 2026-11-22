---
title: prometheus
description: The prometheus endpoint, which returns every metric in the Prometheus text exposition format.
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

The endpoint serves the metrics of [nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics#readme):
what its store holds, and the metrics read during the scrape. Exposing the endpoint does not start
or stop the recording. laravel-metrics records whenever `METRICS_ENABLED` is `true`, which is the
default.

The endpoint does not need `METRICS_PROMETHEUS`. That variable turns on the `/metrics` route of
laravel-metrics itself, a second address for the same output.

## Response

| Case                              | Status | Body                                              |
| --------------------------------- | ------ | ------------------------------------------------- |
| The store was read                | 200    | The metrics, in the text exposition format        |
| `METRICS_ENABLED` is `false`      | 200    | Empty, even if the store holds older values       |
| The store cannot be built or read | 503    | A line of text. The exception is reported.        |

The content type is `text/plain; version=0.0.4; charset=utf-8`. The OpenMetrics format is not
offered.

Requests to the actuator endpoints, this one included, are not recorded in
`http.server.request.duration`.

## Format

Each metric becomes a metric family. Families are sorted by name.

```text
# HELP http_server_request_duration_seconds Duration of HTTP server requests.
# TYPE http_server_request_duration_seconds histogram
http_server_request_duration_seconds_bucket{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",otel_scope_name="nwidart/laravel-metrics",url_scheme="https",le="0.005"} 0
http_server_request_duration_seconds_bucket{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",otel_scope_name="nwidart/laravel-metrics",url_scheme="https",le="+Inf"} 3
http_server_request_duration_seconds_sum{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",otel_scope_name="nwidart/laravel-metrics",url_scheme="https"} 0.0241
http_server_request_duration_seconds_count{http_request_method="GET",http_response_status_code="200",http_route="/users/{user}",otel_scope_name="nwidart/laravel-metrics",url_scheme="https"} 3
# HELP orders_placed_total Orders placed, by sales channel.
# TYPE orders_placed_total counter
orders_placed_total{channel="web",otel_scope_name="nwidart/laravel-actuator"} 12
```

A `# HELP` line is present when the metric has a description.

### Names

Names follow the OpenTelemetry rules for Prometheus compatibility.

| Rule                                                       | Example                                                         |
| ---------------------------------------------------------- | --------------------------------------------------------------- |
| Characters other than letters, digits, `_` and `:` become `_` | `http.server.request.duration` → `http_server_request_duration` |
| The unit is appended as a word                             | `s` → `_seconds`, `ms` → `_milliseconds`, `By` → `_bytes`      |
| A counter ends in `_total`                                 | `orders.placed` → `orders_placed_total`                         |

A unit or `_total` that the name already ends with is not repeated. A unit in braces, such as
`{request}`, is an annotation and is not appended. Attribute names become label names with the
first rule, so `http.route` becomes `http_route`.

A meter of `MeterRegistry` with the unit `seconds`, `milliseconds` or `bytes` has the unit `s`, `ms`
or `By`. A timer is always in seconds.

### Labels

| Label             | Present on                                                          |
| ----------------- | ------------------------------------------------------------------- |
| Each attribute    | The samples of the series that has it                               |
| `otel_scope_name` | Every sample. `nwidart/laravel-metrics` for the built-in metrics, `nwidart/laravel-actuator` for the meters of `MeterRegistry`. |
| `metrics.prometheus.labels` | Every sample. Set in `config/metrics.php`. An attribute of the same name wins. |

Labels are sorted by name, with `le` last.

### Types

| Meter                                     | `# TYPE`    | Samples                                           |
| ----------------------------------------- | ----------- | ------------------------------------------------- |
| Counter                                   | `counter`   | One value                                         |
| Gauge                                     | `gauge`     | One value                                         |
| Timer or summary with buckets             | `histogram` | `_bucket` for each bound and `+Inf`, `_sum`, `_count` |
| Timer or summary without buckets          | `histogram` | `_bucket` for `+Inf` only, `_sum`, `_count`       |

Bucket counts are cumulative. Quantiles and maximum values are not published: see
[what is left out](/laravel-actuator/concepts/metrics-model/#what-is-left-out).

### Escaping

In a label value, `\`, `"` and a line feed are written as `\\`, `\"` and `\n`. A job class therefore
reads `laravel_job_name="App\\Jobs\\SendInvoice"`.

## Related

- [Built-in metrics](/laravel-actuator/reference/metrics/)
- [Configuration of `metrics`](/laravel-actuator/reference/configuration/#metrics)
- [Collect metrics with Prometheus](/laravel-actuator/guides/collect-metrics-with-prometheus/)
