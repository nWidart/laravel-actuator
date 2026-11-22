---
title: The metrics model
description: How Laravel Actuator records metrics through laravel-metrics, why PHP needs a store for them, and what it leaves out.
---

## Two packages

Laravel Actuator does not record metrics itself. It requires
[nwidart/laravel-metrics](https://github.com/nWidart/laravel-metrics#readme), which records them
through the OpenTelemetry SDK and keeps them in a store.

| Package          | Gives                                                                     |
| ---------------- | ------------------------------------------------------------------------- |
| laravel-metrics  | The built-in metrics, the store, the labels of every sample, the test fake. Configured in `config/metrics.php`. |
| Laravel Actuator | The `prometheus` endpoint, `MeterRegistry` for your own meters, `actuator:metrics:clear`. Configured in `config/actuator.php`. |

## Meters, names and tags

`MeterRegistry` is modelled on [Micrometer](https://micrometer.io), the metrics library of Spring
Boot. Underneath, each meter is an OpenTelemetry instrument.

A **meter** measures one thing. It has a dotted name, such as `orders.placed`, and **tags**: pairs
of a name and a value, such as `channel=web`. The same name with other tag values is another series
of the same meter. OpenTelemetry calls tags attributes.

| Meter type           | Measures                                          | OpenTelemetry instrument | Example                  |
| -------------------- | ------------------------------------------------- | ------------------------ | ------------------------ |
| Counter              | A total that only goes up                         | Counter                  | Orders placed            |
| Gauge                | A value that goes up and down                     | Gauge                    | Jobs waiting in a queue  |
| Timer                | How often something happened and how long it took | Histogram in seconds     | Payment charges          |
| Distribution summary | How often something happened and how much of it   | Histogram in its own unit | Upload sizes            |

The built-in metrics use the names of the
[OpenTelemetry semantic conventions](https://opentelemetry.io/docs/specs/semconv/), such as
`http.server.request.duration`. Where the conventions define no metric, laravel-metrics uses the
`laravel.` or `php.` namespace.

The names are not those of a monitoring system. `http.server.request.duration` becomes
`http_server_request_duration_seconds` only when the `prometheus` endpoint renders it. The same
metrics can be pushed over OTLP with the `metrics:push` command of laravel-metrics.

## Why a store

A Java application is one long-lived process. Micrometer keeps its meters in memory, and the
endpoint reads that memory.

A PHP application is many short-lived processes that share nothing. A web worker forgets everything
at the end of a request. Queue workers and the scheduler are other processes, often on other
machines. The request that answers a scrape knows nothing of the work the others did.

So the measurements must live outside the processes, in a **store** that all of them reach:

```text
web worker ──┐
web worker ──┤ write
queue worker ┼────────▶ store ◀──────── read ── the request that answers the scrape
scheduler ───┘
```

The default store is Redis. A Laravel cache store works for development without Redis. See
[choose a metrics store](/laravel-actuator/guides/choose-a-metrics-store/).

APCu, a common choice for PHP metrics, is not offered. The command line does not share APCu memory
with the web server, so the meters of queue workers and of the scheduler would never reach the
endpoint.

## What a request costs

A process does not write to the store for each measurement. It records in its own memory, and
writes once:

- at the end of a request, after the response is sent,
- after each job, in a queue worker,
- at the end of a command.

With the Redis store, that write is one script call. A store failure never fails the request.

Recording does not depend on the `prometheus` endpoint. laravel-metrics records whenever
`METRICS_ENABLED` is `true`, which is the default. Set it to `false` to record nothing.

## Stored meters and meters read on export

A counter or a timer must add up what every process did, so it is stored.

Some values need no store. The size of a queue, the memory of OPcache or the free disk space can be
read at the moment of the scrape. A gauge or a counter with a callback is read then, in the process
that answers, and is never stored.

A meter of this kind describes what that one process can see. For OPcache, PHP-FPM and the disk,
this is the server that answered the scrape.

## Bounded series

Each combination of tag values is a series that is stored and scraped. A tag whose values are not
bounded creates series without end, and fills the store and Prometheus.

The built-in metrics only use bounded values: the route template `/users/{user}`, never the path
`/users/42`; the class of a job or an exception, never its message; the operation of a query, never
its SQL. Follow the same rule for your own tags.

## Histograms, not percentiles

A histogram publishes **buckets**: how many observations took at most 100 milliseconds, at most 250
milliseconds, and so on. Prometheus computes percentiles from them.

Buckets are plain counters, so the counts of all processes and servers add up. A percentile that
each process computes for itself cannot be combined with the others. For this reason the package
publishes buckets, and never percentiles or maximum values.

The built-in duration metrics have buckets. A timer or summary of `MeterRegistry` has none until
you set them in
[`metrics.distribution.slo`](/laravel-actuator/reference/configuration/#metricsdistributionslo).
Until then it publishes only its count and sum.

## What is left out

| Feature                                       | Why it is left out                                                        |
| --------------------------------------------- | ------------------------------------------------------------------------- |
| The JSON `metrics` endpoint of Spring Boot    | Not yet.                                                                  |
| Client-side percentiles and maximum values    | Cannot be combined across processes. Use histogram buckets.               |
| `@Timed`, long task timers                    | Use `Timer::time()`.                                                      |
| Process meters: threads, CPU time, memory     | A PHP process serves one request. OPcache and PHP-FPM metrics take their place. |
| HTTP client and mail metrics                  | Not yet.                                                                  |
