---
title: Use with load balancers and containers
description: Configure load balancer target health checks, Docker HEALTHCHECK, Docker Compose and uptime monitors with the actuator health endpoint.
---

Most health checkers decide on the HTTP status: 200 is healthy, anything else is not. The health
endpoint returns 503 for `DOWN` and `OUT_OF_SERVICE`, so every checker below works without parsing
the JSON.

Pick the path by what the checker does when it fails:

| The checker...                                  | Use                           |
| ----------------------------------------------- | ----------------------------- |
| stops sending traffic to the instance           | `/actuator/health/readiness`  |
| restarts or replaces the instance               | `/actuator/health/liveness`   |
| alerts a human                                  | `/actuator/health`            |

## AWS Application Load Balancer

Set the target group health check:

| Setting              | Value                         |
| -------------------- | ----------------------------- |
| Protocol             | HTTP                          |
| Path                 | `/actuator/health/readiness`  |
| Success codes        | `200`                         |
| Interval             | 10 seconds                    |
| Timeout              | 5 seconds                     |
| Unhealthy threshold  | 2                             |

With Terraform:

```hcl
resource "aws_lb_target_group" "app" {
  # ...
  health_check {
    path                = "/actuator/health/readiness"
    matcher             = "200"
    interval            = 10
    timeout             = 5
    unhealthy_threshold = 2
  }
}
```

The same settings apply to Google Cloud load balancers, Azure Application Gateway and most other
load balancers.

## Docker HEALTHCHECK

```dockerfile title="Dockerfile"
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD curl --fail --silent http://localhost:8080/actuator/health/liveness || exit 1
```

`curl --fail` exits with an error on HTTP 503. If the image has no `curl`, use PHP:

```dockerfile title="Dockerfile"
HEALTHCHECK CMD php -r 'exit(@file_get_contents("http://localhost:8080/actuator/health/liveness") === false ? 1 : 0);'
```

## Docker Compose

Start a service only when the application is ready:

```yaml title="compose.yaml"
services:
  app:
    build: .
    healthcheck:
      test: ["CMD", "curl", "--fail", "--silent", "http://localhost:8080/actuator/health/readiness"]
      interval: 10s
      timeout: 3s
      retries: 5

  worker:
    build: .
    command: php artisan queue:work
    depends_on:
      app:
        condition: service_healthy
```

## Uptime monitors

Point external monitors, such as Oh Dear, UptimeRobot or Pingdom, at `/actuator/health`. Configure
them to alert on any status other than 200. To alert on one dependency only, give it a
[group](/laravel-actuator/guides/define-health-groups/) and monitor the group path.

## Many checkers on one instance

A load balancer, a container health check and an uptime monitor each call the health endpoint, on
every instance. Each call runs every indicator, one after another. Set a short
[health cache](/laravel-actuator/reference/configuration/#healthcache), 2 to 5 seconds, in a store
local to the instance, so the checks share one run:

```ini title=".env"
ACTUATOR_HEALTH_CACHE_TTL=3
ACTUATOR_HEALTH_CACHE_STORE=apc
```

## Laravel's `/up` route

Laravel applications have a built-in `/up` health route. It returns 200 when the application boots
and, by default, checks no dependency. You can keep both:

- `/up` for the simplest liveness checks.
- `/actuator/health/readiness` and `/actuator/health` when the check must see maintenance mode or
  dependencies.

## See also

- [Configure Kubernetes probes](/laravel-actuator/guides/kubernetes-probes/)
- [Health endpoint reference](/laravel-actuator/reference/endpoints/health/)
