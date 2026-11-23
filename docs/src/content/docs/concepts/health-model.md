---
title: The health model
description: How indicators, components, status aggregation, HTTP codes and visibility work together in the health endpoint.
---

## Indicators and components

The health of an application is the sum of the health of its parts. Each part has an **indicator**:
a small class that checks one thing and answers with a status and some details. In the response,
each indicator becomes a **component**, named after its key in the configuration.

The separation matters. An indicator knows how to check a database. The configuration decides which
databases to check and under which names. The same class can check three connections as three
components.

## Five statuses

A boolean "healthy or not" is not enough, so there are five statuses:

- `UP`: the component works.
- `DOWN`: the component fails. Something is broken.
- `OUT_OF_SERVICE`: the component works, but it must not take traffic. Nothing is broken, the state
  is intended, for example during maintenance.
- `WARNING`: the component works, but it is degraded. For example, a disk at 80%, a certificate that
  expires in 10 days, or a queue backlog above a soft threshold. It still takes traffic.
- `UNKNOWN`: the indicator cannot tell.

`DOWN` and `OUT_OF_SERVICE` lead to the same action from a load balancer, stop sending traffic, but
mean different things to a human. One calls for an investigation, the other for patience.

## Aggregation: the most severe status wins

The overall status of the application, or of a group, is the most severe status of its components:

```
DOWN  >  OUT_OF_SERVICE  >  WARNING  >  UP  >  UNKNOWN
```

So one failing component makes the whole application `DOWN`. This is pessimistic on purpose. A
checker that sees `UP` must be able to trust that every checked part works.

`UNKNOWN` ranks below `UP`. An indicator that cannot decide does not drag the application down, and
an application with only `UNKNOWN` components is `UNKNOWN`. With no component at all, there is
nothing to report, and the status is `UNKNOWN` too.

`WARNING` ranks above `UP`, so one degraded component shows in the overall status, without taking the
application out of traffic.

To change the order, set
[`health.status.order`](/laravel-actuator/reference/configuration/#healthstatus). A group can set its
own.

The consequence: only check what must work. If a component may fail while the application still
serves users, a failure of that component should not make the whole application `DOWN`. Leave it
out, or put it in a [group](/laravel-actuator/guides/define-health-groups/) of its own.

## HTTP status codes

Most health checkers do not read JSON. They read the HTTP status. The endpoint returns 503 for
`DOWN` and `OUT_OF_SERVICE`, and 200 for `WARNING`, `UP` and `UNKNOWN`. So a checker that only knows
"200 is good" makes the right decision without any configuration.

`UNKNOWN` returns 200 for the same reason it ranks below `UP`: lack of information is not a
failure. `WARNING` returns 200 because a degraded component still serves users.

To change the codes, set
[`health.status.http_mapping`](/laravel-actuator/reference/configuration/#healthstatus), for example
to return 500 for `DOWN`. A group can set its own.

## Exceptions are failures

An indicator does not need to catch its own errors. When a check throws, the component is `DOWN`
and the exception message goes into the `error` detail. The other indicators still run. A broken
check must never break the health endpoint itself, because a health endpoint that returns HTTP 500
tells a checker nothing about which part failed.

Configuration errors are different. These are mistakes by the developer, not runtime failures:

- A group that includes an unknown indicator.
- A class that is not an indicator.
- In the `status` settings of the endpoint or of a group: an unknown status code, an `order` that is
  not a list, or an `http_mapping` code that is not an integer from 100 to 599.

These throw, so that the mistake is visible at the first request instead of hiding as a permanent
`DOWN`.

## Visibility: status, components, details

The health response has three layers, from least to most revealing:

1. **Status.** One word. Always visible. Probes need nothing else.
2. **Components.** Which indicators exist and the status of each. This reveals the architecture of
   the application: which databases, caches and external services it uses.
3. **Details.** The content of each component: connection names, driver versions, disk paths, error
   messages. Error messages can include host names and query fragments.

Each layer has its own setting, `show_components` and `show_details`, so that you can, for example,
show components to an internal dashboard and keep details for authenticated operators. By default
`show_components` follows `show_details`, and both are `never`.

The status is always computed from every component, hidden or not. Hiding changes what a reader
sees, never the verdict.

## Groups

A group is a named subset of the indicators, with its own path and, if needed, its own visibility.
Groups exist because different checkers ask different questions. A liveness probe asks "should I
restart you?", a readiness probe asks "should I send you traffic?", an on-call engineer asks "what is
wrong?". One aggregated status cannot answer all three.

See [liveness and readiness](/laravel-actuator/concepts/liveness-and-readiness/) for the two groups
the package defines.

## No caching

Each request runs every indicator of the requested path. The response always reflects the current
state, which is what probes expect. The cost is a database query and a cache round trip for each
request. For a check that is slow or expensive, compute the result in a scheduled job and let the
indicator read it, as described in
[write a custom health indicator](/laravel-actuator/guides/write-a-health-indicator/#keep-the-check-fast).
