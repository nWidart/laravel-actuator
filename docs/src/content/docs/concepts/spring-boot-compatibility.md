---
title: Spring Boot Actuator compatibility
description: Why Laravel Actuator follows Spring Boot Actuator, what it shares with it, and where it differs.
---

## Why follow Spring Boot

Monitoring endpoints are a contract between an application and the tools around it: orchestrators,
load balancers, dashboards, alerting. A contract is only useful if both sides agree on it.

Spring Boot Actuator is the most widely deployed contract of this kind. Kubernetes examples, Helm
charts, Spring Boot Admin and many internal platforms already assume its paths and its JSON. A team
that runs Spring Boot and Laravel services side by side can point the same probes and dashboards
at both.

Laravel Actuator therefore does not invent a format. Where Spring Boot has an answer, the package
reuses it, and an operator who knows one knows the other.

## What is the same

**Paths.** `/actuator`, `/actuator/health`, `/actuator/health/{group}`,
`/actuator/health/{component}`, `/actuator/info`, `/actuator/env`, `/actuator/mappings`,
`/actuator/scheduledtasks` and `/actuator/prometheus`.

**Discovery.** `/actuator` returns HAL `_links`, including the templated `health-path` link.

**Health semantics.**

- The four statuses `UP`, `DOWN`, `OUT_OF_SERVICE` and `UNKNOWN`.
- The default severity order, `DOWN`, `OUT_OF_SERVICE`, `UP`, `UNKNOWN`.
- HTTP 503 for `DOWN` and `OUT_OF_SERVICE`, HTTP 200 otherwise.
- `show_details` and `show_components` with `never`, `when_authorized` and `always`.
- Groups, with `liveness` and `readiness` for Kubernetes probes.
- Many indicator names: `ping`, `readinessState`, `diskSpace`, `db`, `redis`.

**JSON formats.** `health`, `info`, `env` and `scheduledtasks` return the structures Spring Boot
returns, so clients written for Spring Boot can parse them.

**Metrics API.** A meter registry with counters, gauges, timers and distribution summaries, and
tags, as in Micrometer. The metric names and tags are not those of Spring Boot: see
[what differs](#what-differs).

**Exposure.** Only `health` is exposed by default, with `include` and `exclude` lists and the `*`
wildcard.

**Custom endpoints.** An endpoint has an ID and read, write and delete operations on `GET`, `POST`
and `DELETE`. Selectors are path segments. A read operation without a result answers HTTP 404, and
a write or delete operation without a result answers HTTP 204. The
[custom endpoints reference](/laravel-actuator/reference/custom-endpoints/#differences-from-spring-boot)
lists what is left out.

## What differs

| Topic               | Spring Boot                                          | Laravel Actuator                                      |
| ------------------- | ---------------------------------------------------- | ----------------------------------------------------- |
| `mappings` format   | Grouped by dispatcher servlet and handler            | A flat list of Laravel routes                         |
| `env` sources       | Many property sources, such as `application.yml` and system properties | Two: `config` and `environment`        |
| `env` profiles      | Spring profiles                                      | The Laravel environment                               |
| `scheduledtasks`    | `cron`, `fixedDelay`, `fixedRate`, `custom`          | `cron` and `fixedRate`. The others are always empty.  |
| `info` content      | `build`, `git`, `java`, `os`, `process`              | `app`, `git`, `php`, `os`, `process`                  |
| `when_authorized`   | Spring Security roles                                | The `viewActuator` gate                               |
| Liveness state      | An application state that the application can change | The `ping` indicator: alive while it can answer       |
| Readiness state     | An application state that the application can change | Maintenance mode                                      |
| Metric names and tags | Micrometer names, such as `http.server.requests` with `uri` and `outcome` | OpenTelemetry names, such as `http.server.request.duration` with `http.route`, from [laravel-metrics](https://github.com/nWidart/laravel-metrics#readme) |
| Prometheus names    | Micrometer naming, such as `http_server_requests_seconds` | OpenTelemetry naming, such as `http_server_request_duration_seconds`, with an `otel_scope_name` label |
| Metrics storage     | The memory of the one application process            | A store shared by the PHP processes: Redis, or a cache store |
| Metrics backends    | Many monitoring systems, and the JSON `metrics` endpoint | The `prometheus` endpoint, or OTLP with laravel-metrics |
| Runtime meters      | JVM memory, threads, garbage collection              | OPcache and PHP-FPM                                   |
| Other endpoints     | `metrics`, `loggers`, `beans`, `threaddump` and more | Not built in. Write a [custom endpoint](/laravel-actuator/guides/write-a-custom-endpoint/) |
| Separate port       | `management.server.port`                             | Same server as the application                        |
| Configuration       | `management.*` properties                            | `config/actuator.php`                                 |

Some differences come from the platform. PHP has no dispatcher servlets, no thread dumps and no
long-lived application context to hold a liveness state. The package maps each concept to its
closest Laravel equivalent and leaves out what has none.

## Spring Boot Admin

[Spring Boot Admin](https://github.com/codecentric/spring-boot-admin) discovers the endpoints from
the `_links` of `/actuator` and shows a view for each one it knows.

| View             | Works                                                  |
| ---------------- | ------------------------------------------------------ |
| Health           | Yes                                                    |
| Info, details    | Yes                                                    |
| Environment      | Yes                                                    |
| Scheduled tasks  | Yes                                                    |
| Mappings         | No: the format differs                                 |
| Metrics          | No: Spring Boot Admin reads the JSON `metrics` endpoint, which does not exist |
| Loggers, threads and others | No: the endpoints do not exist               |

See [monitor with Spring Boot Admin](/laravel-actuator/guides/spring-boot-admin/).
