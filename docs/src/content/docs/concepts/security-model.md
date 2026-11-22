---
title: Security model
description: Why only health is exposed by default, how exposure, middleware and visibility combine, and what each endpoint reveals.
---

## What an attacker learns

Monitoring endpoints exist to reveal the inside of an application. That is useful to an operator,
and as useful to an attacker:

| Endpoint         | Reveals                                                                  |
| ---------------- | ------------------------------------------------------------------------ |
| `health` status  | Whether the application is up. Nearly nothing.                           |
| `health` components | Which databases, caches and services the application depends on.      |
| `health` details | Connection names, driver versions, disk paths, error messages.           |
| `info`           | Laravel and PHP versions, which tell an attacker which known vulnerabilities to try. |
| `env` keys       | Every configured service and package.                                    |
| `env` values     | Every secret: `APP_KEY`, database passwords, API keys.                   |
| `mappings`       | Every route, including admin and internal routes, with their middleware.  |
| `scheduledtasks` | Background jobs and when they run.                                       |
| `prometheus`     | Route patterns, database and cache names, job and exception class names, versions, traffic and error rates. |

## Secure by default

The defaults are chosen so that installing the package reveals nothing beyond what a probe needs:

- Only `health` is exposed. A probe must work without configuration, so this one endpoint is open.
- `health` shows only the overall status.
- `env` masks every value, even after you expose it.
- `when_authorized` grants nothing until you define the `viewActuator` gate.

Every step towards more information is an explicit decision in your configuration.

## Three independent layers

Access is controlled by three settings. Each answers a different question:

1. **Exposure** (`exposure.include`, `exposure.exclude`): does the endpoint exist? An endpoint that is
   not exposed has no route. Nothing can reach it, whatever the middleware.
2. **Middleware** (`middleware`, `<endpoint>.middleware`): who may call it? This is ordinary Laravel
   middleware, so any authentication method works: sessions, Sanctum tokens, basic authentication, a
   custom header check.
3. **Visibility** (`show_details`, `show_components`, `show_values`): how much does a permitted caller
   see? `when_authorized` delegates the decision to the `viewActuator` gate.

The layers combine. A common setup for `health` exposes it, applies no blocking middleware so probes
can call it, and uses `when_authorized` visibility so only operators see the details. A common setup
for `env` exposes it, puts authentication middleware in front, and keeps `show_values` at `never`, so
even an operator sees the keys but not the secrets.

## Why visibility uses a gate

Laravel already has a place for authorization decisions: gates and policies. Reusing a gate means
the rule lives with your other rules, can use your user model and roles, and can be tested like any
other gate. Spring Boot does the same with Spring Security roles.

The gate only decides about a user that something has authenticated. The actuator routes do not
belong to the `web` group, so the route itself must carry middleware that lets the guard find the
user. Without it, the user is `null`, and the gate refuses.

## Why middleware is per endpoint

The endpoints have very different audiences. Probes call `health` from inside the cluster, often
without credentials. Humans or admin tools call `env` and `mappings`. One middleware stack for all
endpoints would force a choice between open probes and protected configuration. Per-endpoint
middleware removes that choice. The shared `middleware` key remains for what applies everywhere,
such as rate limiting or an IP allowlist.

## Defence in depth

Middleware protects the application layer. It does not replace network controls. The safest place
for `/actuator` is a network that the public internet cannot reach: an internal port, a path blocked
at the ingress, a private load balancer. Use both when you can, so that a mistake in one layer does
not expose the endpoints.

## Errors in details

When an indicator throws, the exception message becomes the `error` detail. Database exceptions can
include host names, user names and parts of queries. This is one more reason why `show_details`
defaults to `never`, and why custom indicators must never put secrets in their details.

See [secure the endpoints](/laravel-actuator/guides/secure-endpoints/) for the configuration.
