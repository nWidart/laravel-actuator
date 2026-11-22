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
