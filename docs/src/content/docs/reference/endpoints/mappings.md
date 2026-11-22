---
title: mappings
description: The mappings endpoint, which lists every registered route with its methods, URI, name, action, middleware and domain.
---

Lists every route registered in the application, actuator routes included.

| Property      | Value                                                     |
| ------------- | --------------------------------------------------------- |
| ID            | `mappings`                                                |
| Path          | `GET /actuator/mappings`                                  |
| Route name    | `actuator.mappings`                                       |
| Exposure      | Not exposed by default                                    |
| Middleware    | `middleware`, then `mappings.middleware`                  |
| Controller    | `Nwidart\Actuator\Http\Controllers\MappingsController`    |

:::note
This format is specific to Laravel. Spring Boot groups mappings by dispatcher servlet, which has no
Laravel equivalent, so Spring Boot tools cannot read this response.
:::

## Response

HTTP 200 with a JSON object holding `routes`, a list in registration order. Each route:

| Key          | Type           | Description                                                          |
| ------------ | -------------- | -------------------------------------------------------------------- |
| `methods`    | array          | HTTP methods, for example `["GET", "HEAD"]`.                         |
| `uri`        | string         | URI pattern without a leading slash, for example `users/{user}`.      |
| `name`       | string or null | Route name.                                                          |
| `action`     | string         | Controller action, `Closure` for closure routes.                     |
| `middleware` | array          | Every middleware of the route, groups included. Non-string middleware shows its type. |
| `domain`     | string or null | Domain constraint.                                                   |

## Example

```bash
curl http://localhost/actuator/mappings
```

```json
{
  "routes": [
    {
      "methods": ["GET", "HEAD"],
      "uri": "users/{user}",
      "name": "users.show",
      "action": "App\\Http\\Controllers\\UserController@show",
      "middleware": ["web", "auth"],
      "domain": null
    },
    {
      "methods": ["GET", "HEAD"],
      "uri": "actuator/health/{path?}",
      "name": "actuator.health",
      "action": "Nwidart\\Actuator\\Http\\Controllers\\HealthController",
      "middleware": [],
      "domain": null
    }
  ]
}
```
