---
title: Liveness, readiness and maintenance mode
description: What liveness and readiness mean, why the default groups are so small, and how maintenance mode drains traffic.
---

## Two questions, two answers

An orchestrator asks a running instance two different questions:

- **Liveness**: is this instance broken beyond repair? If yes, kill it and start a new one.
- **Readiness**: can this instance handle requests right now? If not, stop sending traffic to it,
  but leave it alone.

The actions differ in cost. Taking an instance out of rotation is cheap and reversible. Restarting
it is expensive, drops the requests it is serving, and only helps if the problem lives inside the
instance.

## Why liveness checks almost nothing

The default `liveness` group contains only `ping`, which is always `UP`. If the instance can answer
the request at all, it is alive.

This looks too simple, but consider what a richer liveness check does. Add the database to it, and
the database goes down. Every instance fails liveness. The orchestrator restarts every instance. The
new instances cannot reach the database either, fail again, and restart again. The outage of one
dependency turns into a restart loop of the whole fleet, and the instances are not ready to serve
when the database comes back.

A restart fixes problems inside the process: a deadlock, a corrupted state, memory exhaustion. PHP
creates a fresh state for each request, so these problems are rare in a Laravel application. When the
PHP-FPM or web server process stops answering, the liveness probe times out, and that is the failure
liveness exists to catch.

A restart does not fix a queue backlog either: restarting web pods does not drain a queue. Keep the
queue indicators out of liveness. See [Monitor queues](/laravel-actuator/guides/monitor-queues/).

## Why readiness checks maintenance mode

The default `readiness` group contains `readinessState`, which reports `OUT_OF_SERVICE` while the
application is in maintenance mode.

Maintenance mode is the Laravel way to say "do not send me traffic". Readiness is the orchestrator
way to hear it. With this group, `php artisan down` drains an instance from the load balancer, and
`php artisan up` brings it back, without restarts.

To make this work, the package lets requests under the actuator base path through maintenance mode.
Without that, the probe would receive Laravel's HTML maintenance page. It would still see a 503, but
monitoring tools could no longer read the status.

## Why readiness checks pending migrations

The default `readiness` group also contains `migrations`, which reports `OUT_OF_SERVICE` while
migrations have not run. During a rolling deploy, new instances can start before
`php artisan migrate` has run. Their code expects tables and columns the database does not have
yet. They stay out of rotation until the migrations have run, and the old instances keep serving.

The indicator reads the database, so it reports `UNKNOWN` instead of `DOWN` when the database
cannot be reached. A database outage is a shared failure (see below): it does not make every
instance unready at once.

## Should readiness check the database?

It depends on whether the failure is local or shared.

**Local failures** affect one instance: a full disk, a broken local socket, a connection pool
exhausted in one pod. Readiness should check these. The orchestrator moves traffic to the healthy
instances, and users see nothing.

**Shared failures** affect every instance at once: the primary database is down, the shared Redis
is unreachable. If readiness checks these, every instance becomes unready at the same moment. The
load balancer then has no target, and users get a generic error from the load balancer instead of
your application's error page. Your application can often do better on its own: serve cached pages,
queue writes, show a clear message.

So there is no universal answer. The package leaves `db` out of `readiness` by default and lets you
choose. If you add it, do so knowing that a database outage becomes a total outage at the load
balancer.

## The full health endpoint is for humans

`/actuator/health` aggregates every indicator. It is the right target for alerting and dashboards: a
human wants to know when the database is down, even if no probe should act on it. It is the wrong
target for probes, because it mixes local and shared failures, and any of them would trigger the
probe's action.

## Startup

Kubernetes also has startup probes, which hold liveness checks back until the application has
started. A Laravel request does not depend on a long boot sequence, so most applications do not need
one. Add one when a container runs slow work before it can serve, such as cache warming.
