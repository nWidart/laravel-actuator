---
title: Configure Kubernetes probes
description: Point Kubernetes liveness, readiness and startup probes at the actuator health groups, and drain pods with maintenance mode.
---

The default `liveness` and `readiness` groups match the Kubernetes probes. Kubernetes treats any
HTTP status from 200 to 399 as success, so `DOWN` and `OUT_OF_SERVICE` (HTTP 503) count as
failures.

## Add the probes

```yaml title="deployment.yaml"
spec:
  containers:
    - name: app
      image: my-app:1.4.2
      ports:
        - containerPort: 8080
      livenessProbe:
        httpGet:
          path: /actuator/health/liveness
          port: 8080
        periodSeconds: 10
        timeoutSeconds: 2
        failureThreshold: 3
      readinessProbe:
        httpGet:
          path: /actuator/health/readiness
          port: 8080
        periodSeconds: 5
        timeoutSeconds: 2
        failureThreshold: 2
```

Replace `8080` with the port your web server listens on inside the container. Keep `timeoutSeconds`
above the slowest indicator in the group.

## Add a startup probe

When the container needs time before it serves requests, for example to warm caches, add a startup
probe. Kubernetes waits for it before it runs the liveness probe:

```yaml title="deployment.yaml"
      startupProbe:
        httpGet:
          path: /actuator/health/liveness
          port: 8080
        periodSeconds: 2
        failureThreshold: 30
```

## Probe workers and schedulers

Pods that run `queue:work`, `horizon` or `schedule:work` have no HTTP server. Use an `exec` probe
with `php artisan actuator:health`. It runs the same checks as the endpoint and exits `0` when the
status maps to an HTTP 2xx, `1` otherwise:

```yaml title="worker-deployment.yaml"
spec:
  containers:
    - name: worker
      image: my-app:1.4.2
      args: ["php", "artisan", "queue:work"]
      livenessProbe:
        exec:
          command: ["php", "artisan", "actuator:health", "liveness"]
        periodSeconds: 30
        timeoutSeconds: 10
        failureThreshold: 3
      readinessProbe:
        exec:
          command: ["php", "artisan", "actuator:health", "readiness"]
        periodSeconds: 30
        timeoutSeconds: 10
        failureThreshold: 3
```

Each probe boots the application in a new PHP process, which costs more than an HTTP request. Keep
`periodSeconds` at 30 or more, and `timeoutSeconds` above the boot time plus the slowest
indicator. Use the same probes for a scheduler pod.

The command ignores `show_details` and `show_components`, and never uses the health cache. An
unknown group or component exits `2`. Run it by hand to see why a pod fails:

```bash
kubectl exec deploy/my-worker -- php artisan actuator:health readiness --details
```

## Choose what readiness checks

By default, `readiness` checks maintenance mode and pending migrations. Add the dependencies without which an instance
cannot serve any request, and that can fail for one instance but not the others:

```php title="config/actuator.php"
'groups' => [
    'liveness' => ['include' => ['ping']],
    'readiness' => ['include' => ['readinessState', 'migrations', 'diskSpace']],
],
```

Keep `liveness` minimal. A liveness failure restarts the container, which does not fix a database
outage. [Liveness and readiness](/laravel-actuator/concepts/liveness-and-readiness/) explains the
trade-offs.

## Cache the results

The kubelet probes every pod every few seconds, and other checkers may too. When an indicator is
slow, every probe waits for it. Reuse each result for a few seconds, in a store local to the pod:

```ini title=".env"
ACTUATOR_HEALTH_CACHE_TTL=3
ACTUATOR_HEALTH_CACHE_STORE=file
```

Use `file` when `storage` is local to the container, `apc` when PHP-FPM has the APCu extension, or
`array` under Octane. Never use a store shared between pods, such as `redis`: one pod would report
the health of another.

Keep the TTL below the probe's `periodSeconds`. A longer TTL keeps a stale `DOWN` and holds a
recovered pod out of the Service.

## Take pods out of rotation with maintenance mode

While the application is in maintenance mode, `readiness` reports `OUT_OF_SERVICE` and `liveness`
stays `UP`. Kubernetes stops sending traffic but does not restart the pods.

By default, maintenance mode is a file in each pod, so `php artisan down` affects only the pod where
you run it:

```bash
kubectl exec deploy/my-app -- php artisan down
```

To take every pod out at once, store maintenance mode in a shared cache:

```ini title=".env"
APP_MAINTENANCE_DRIVER=cache
APP_MAINTENANCE_STORE=redis
```

```bash
kubectl exec deploy/my-app -- php artisan down
kubectl exec deploy/my-app -- php artisan up
```

:::caution
When every pod is unready, the Service has no endpoints, and clients receive errors from the
ingress instead of your maintenance page. Use this to drain traffic on purpose, for example during
a migration.
:::

## Check the probes

```bash
kubectl describe pod -l app=my-app | grep -A3 -E 'Liveness|Readiness'
kubectl get endpoints my-app
```

A pod that fails readiness disappears from the endpoints list. Its events show the failed probe and
the HTTP status.

## See also

- [Define health groups](/laravel-actuator/guides/define-health-groups/)
- [Liveness and readiness](/laravel-actuator/concepts/liveness-and-readiness/)
- [Kubernetes probe documentation](https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/)
