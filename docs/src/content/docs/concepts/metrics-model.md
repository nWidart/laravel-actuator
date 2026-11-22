---
title: The metrics model
description: How Laravel Actuator models meters after Micrometer, why PHP needs a store for them, and what it leaves out.
---

## Meters, names and tags

The model is the one of [Micrometer](https://micrometer.io), the library behind the metrics of
Spring Boot Actuator.

A **meter** measures one thing. It has a dotted name, such as `http.server.requests`, and **tags**:
pairs of a name and a value, such as `method=GET`. The same name with other tag values is another
series of the same meter.

A **meter registry** creates the meters and reads them back. There is one, `MeterRegistry`.

| Meter type           | Measures                                         | Example                         |
| -------------------- | ------------------------------------------------ | ------------------------------- |
| Counter              | A total that only goes up                        | Orders placed                   |
| Gauge                | A value that goes up and down                    | Jobs waiting in a queue         |
| Timer                | How often something happened and how long it took | HTTP requests                  |
| Distribution summary | How often something happened and how much of it  | Upload sizes                    |

The names are not those of a monitoring system. `http.server.requests` becomes
`http_server_requests_seconds` only when the `prometheus` endpoint renders it. This keeps the
meters independent of Prometheus: another format can render the same meters under its own names.

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

This is the main difference with Spring Boot, and the only part that needs a decision from you:
[choose a metrics store](/laravel-actuator/guides/choose-a-metrics-store/).

APCu, a common choice for PHP metrics, is not offered. The command line does not share APCu memory
with the web server, so the meters of queue workers and of the scheduler would never reach the
endpoint.

## What a request costs

A process does not write to the store for each measurement. It adds the measurement to an array in
its own memory, and writes once:

- at the end of a request, after the response is sent,
- after each job, in a queue worker,
- at the end of a command.

The cost for a request is a few array operations while it runs, and one write to the store when it
is over. With the Redis store, that write is one round trip.

While the `prometheus` endpoint is not exposed, the package registers no listener and builds no
registry. The cost is then zero.

## Stored meters and meters read during the scrape

A counter or a timer must add up what every process did, so it is stored.

Some gauges need no store. The size of a queue, the memory of OPcache or the free disk space can be
read at the moment of the scrape. These gauges take a callback, which runs in the process that
answers.

A gauge of this kind describes what that one process can see. For OPcache, PHP-FPM and the disk,
this is the server that answered the scrape.

## Bounded series

Each combination of tag values is a series that is stored and scraped. A tag whose values are not
bounded creates series without end, and fills the store and Prometheus.

The built-in meters only use bounded values:

- the route pattern `/users/{user}`, never the path `/users/42`,
- `NOT_FOUND` for every path without a route,
- `OTHER` for every HTTP method that is not a standard one,
- the class of a job or an exception, never its message,
- the connection of a query, never its SQL.

Follow the same rule for your own tags.

## Histograms, not percentiles

A timer can publish **buckets**: how many observations took at most 100 milliseconds, at most 250
milliseconds, and so on. Prometheus computes percentiles from them.

Buckets are plain counters, so the counts of all processes and servers add up. A percentile that
each process computes for itself cannot be combined with the others. For this reason the package
publishes buckets, and never percentiles or maximum values.

Spring Boot publishes no buckets by default. This package enables them for its four timers, because
the average alone hides slow requests. Change or remove them in
[`metrics.distribution.slo`](/laravel-actuator/reference/configuration/#metricsdistributionslo).

## What is the same as Spring Boot

- The registry, the meter types, tags and common tags.
- The names `http.server.requests` and `tasks.scheduled.execution`, and the tags `method`, `uri`,
  `status`, `outcome` and `exception` of the request meter.
- The names `system.load.average.1m`, `disk.free` and `disk.total`.
- Turning meters off by name prefix, as `management.metrics.enable.*` does.
- The `prometheus` endpoint at `/actuator/prometheus`, not exposed by default, with the Prometheus
  names of Micrometer.

## What is left out

| Spring Boot                                   | Why it is left out                                                        |
| --------------------------------------------- | ------------------------------------------------------------------------- |
| The JSON `metrics` endpoint                   | Not yet. The meters keep their dotted names and units, so it can be added. |
| Several registries, one for each monitoring system | One exposition format exists today. The meters do not depend on it.  |
| Client-side percentiles and maximum values    | Cannot be combined across processes. Use histogram buckets.               |
| Pushgateway, exemplars, the Observation API   | Outside the scope of a scrape endpoint.                                   |
| `@Timed`, long task timers                    | Use `Timer::time()`.                                                      |
| JVM and process meters: threads, GC, CPU, uptime | A PHP process serves one request. OPcache and PHP-FPM meters take their place. |
| HTTP client, cache and mail meters            | Not yet.                                                                  |
