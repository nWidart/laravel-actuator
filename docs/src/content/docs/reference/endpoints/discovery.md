---
title: Discovery
description: The /actuator endpoint, which links to every exposed endpoint in the HAL format.
---

Lists links to the exposed endpoints.

| Property      | Value                                       |
| ------------- | ------------------------------------------- |
| Path          | `GET /{base_path}`, default `GET /actuator` |
| Route name    | `actuator.discovery`                        |
| Exposure      | Always registered                           |
| Middleware    | `middleware`                                |
| Controller    | `Nwidart\Actuator\Http\Controllers\DiscoveryController` |

## Response

HTTP 200 with a JSON object holding `_links`. Each link has an absolute `href` and a `templated`
flag.

| Link             | Present when                 | `href`                                  | `templated` |
| ---------------- | ---------------------------- | --------------------------------------- | ----------- |
| `self`           | Always                       | The discovery URL                       | `false`     |
| `health`         | `health` is exposed          | The health URL                          | `false`     |
| `health-path`    | `health` is exposed          | The health URL followed by `/{*path}`   | `true`      |
| `info`           | `info` is exposed            | The info URL                            | `false`     |
| `env`            | `env` is exposed             | The env URL                             | `false`     |
| `mappings`       | `mappings` is exposed        | The mappings URL                        | `false`     |
| `scheduledtasks` | `scheduledtasks` is exposed  | The scheduled tasks URL                 | `false`     |

The links use the host and scheme of the request, as Laravel's `route()` helper builds them.

## Example

```bash
curl http://localhost/actuator
```

```json
{
  "_links": {
    "self": { "href": "http://localhost/actuator", "templated": false },
    "health": { "href": "http://localhost/actuator/health", "templated": false },
    "health-path": { "href": "http://localhost/actuator/health/{*path}", "templated": true },
    "info": { "href": "http://localhost/actuator/info", "templated": false }
  }
}
```
