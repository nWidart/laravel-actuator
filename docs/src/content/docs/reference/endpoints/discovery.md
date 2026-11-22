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
| Configuration | [`base_path`](/laravel-actuator/reference/configuration/#base_path), [`middleware`](/laravel-actuator/reference/configuration/#middleware) |
| Class         | `Nwidart\Actuator\Http\Controllers\DiscoveryController` |

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
| `prometheus`     | `prometheus` is exposed      | The Prometheus URL                      | `false`     |
| `{id}`           | A custom endpoint is exposed | The endpoint URL                        | `false`     |
| `{id}-{selector}` | A custom endpoint with a selector is exposed | The endpoint URL followed by `/{selector}` | `true` |

A [custom endpoint](/laravel-actuator/reference/custom-endpoints/#discovery-links) has one link for
each of its paths. Built-in endpoints come first, then custom endpoints in registration order.

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
