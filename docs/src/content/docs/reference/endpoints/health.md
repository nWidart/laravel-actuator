---
title: health
description: The health endpoint, its paths, response format, status aggregation, HTTP status codes and groups.
---

Reports the health of the application, a group, or one component.

| Property      | Value                                                       |
| ------------- | ----------------------------------------------------------- |
| ID            | `health`                                                    |
| Paths         | `GET /actuator/health`, `GET /actuator/health/{path}`       |
| Route names   | `actuator.health`, `actuator.health-path`                   |
| Exposure      | Exposed by default                                          |
| Middleware    | `middleware`, then `health.middleware`                      |
| Configuration | [`health`](/laravel-actuator/reference/configuration/#health) |
| Class         | `Nwidart\Actuator\Http\Controllers\HealthController`        |

## Paths

| Path                                   | Returns                                     | Requires visible components |
| -------------------------------------- | ------------------------------------------- | --------------------------- |
| `/actuator/health`                     | Every indicator                             | No                          |
| `/actuator/health/{group}`             | The indicators of the group                 | No                          |
| `/actuator/health/{component}`         | One indicator                               | Yes                         |
| `/actuator/health/{group}/{component}` | One indicator of the group                  | Yes, with the group's visibility |

The first segment selects a group when a group of that name exists, otherwise a component. Any other
path, a hidden component, or a component outside the group returns HTTP 404.

## Response

### Root

| Key          | Type   | Present when                                     |
| ------------ | ------ | ------------------------------------------------ |
| `status`     | string | Always. The aggregated status.                   |
| `components` | object | Components are visible and at least one indicator is configured. |
| `groups`     | array  | At least one group is configured. Lists the group names. |

### Group

Same as the root, without `groups`.

### Component

| Key       | Type   | Present when                                   |
| --------- | ------ | ---------------------------------------------- |
| `status`  | string | Always.                                        |
| `details` | object | Details are visible and the indicator returned at least one detail. |

Each entry of `components` has the component format.

## Statuses

| Status           | HTTP status | Severity rank |
| ---------------- | ----------- | ------------- |
| `DOWN`           | 503         | 1 (most severe) |
| `OUT_OF_SERVICE` | 503         | 2             |
| `WARNING`        | 200         | 3             |
| `UP`             | 200         | 4             |
| `UNKNOWN`        | 200         | 5             |

The aggregated status of the root or a group is the most severe status among its components. With
no component, the status is `UNKNOWN`. The HTTP status follows the status of the response:
aggregated for the root and groups, the component's own for a component path.

These are the defaults. [`health.status`](/laravel-actuator/reference/configuration/#healthstatus)
changes the order and the HTTP status codes, for the endpoint or for one group.

The `status` key is computed from all components, even when they are hidden.

## Failing indicators

When an indicator throws, its component reports `DOWN` with the detail
`error: "<exception class>: <message>"`. The package logs a warning with the exception. The other
components still run.

A configuration error, such as a class that does not implement `HealthIndicator` or a group that
includes an unknown name, throws `InvalidArgumentException` and returns HTTP 500.

## Groups

Configured in [`health.groups`](/laravel-actuator/reference/configuration/#healthgroups). Defaults:

| Group       | Includes          | Intended for           |
| ----------- | ----------------- | ---------------------- |
| `liveness`  | `ping`            | Liveness probes        |
| `readiness` | `readinessState`  | Readiness probes       |

A group's `show_details` and `show_components` override the endpoint settings for the group path and
its component paths.

## Maintenance mode

Requests under `base_path` bypass Laravel's maintenance mode, so the health endpoint answers with
JSON during `php artisan down`. The `readinessState` indicator reports `OUT_OF_SERVICE` at that time.

## Examples

Default visibility:

```bash
curl -i http://localhost/actuator/health
```

```http
HTTP/1.1 200 OK
Content-Type: application/json

{"status":"UP","groups":["liveness","readiness"]}
```

With `show_details` set to `always`:

```json
{
  "status": "UP",
  "components": {
    "ping": { "status": "UP" },
    "readinessState": { "status": "UP" },
    "diskSpace": {
      "status": "UP",
      "details": { "total": 494384795648, "free": 213212008448, "threshold": 10485760, "path": "/var/www", "exists": true }
    },
    "db": { "status": "UP", "details": { "connection": "mysql", "database": "MySQL", "validationQuery": "select 1" } },
    "cache": { "status": "UP", "details": { "store": "redis" } }
  },
  "groups": ["liveness", "readiness"]
}
```

A failing database, with details:

```http
HTTP/1.1 503 Service Unavailable
Content-Type: application/json
```

```json
{
  "status": "DOWN",
  "components": {
    "db": {
      "status": "DOWN",
      "details": { "error": "Illuminate\\Database\\QueryException: SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, SQL: select 1)" }
    }
  }
}
```

One component:

```bash
curl http://localhost/actuator/health/db
```

```json
{ "status": "UP", "details": { "connection": "mysql", "database": "MySQL", "validationQuery": "select 1" } }
```
