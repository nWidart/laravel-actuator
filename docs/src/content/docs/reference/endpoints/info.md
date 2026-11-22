---
title: info
description: The info endpoint, which merges the output of the info contributors.
---

Returns information about the running application.

| Property      | Value                                                   |
| ------------- | ------------------------------------------------------- |
| ID            | `info`                                                  |
| Path          | `GET /actuator/info`                                    |
| Route name    | `actuator.info`                                         |
| Exposure      | Not exposed by default                                  |
| Middleware    | `middleware`, then `info.middleware`                    |
| Configuration | [`info`](/laravel-actuator/reference/configuration/#info) |
| Controller    | `Nwidart\Actuator\Http\Controllers\InfoController`      |

## Response

HTTP 200 with a JSON object. Each key of `info.contributors` is a top-level key, in configuration
order, with the array its contributor returns. A contributor that returns an empty array is left
out. Without contributors, the response is `{}`.

The [info contributors reference](/laravel-actuator/reference/info-contributors/) describes the
content of each built-in key.

A contributor class that does not implement `InfoContributor` throws `InvalidArgumentException` and
returns HTTP 500.

## Example

```bash
curl http://localhost/actuator/info
```

```json
{
  "app": { "name": "Laravel", "environment": "production", "laravel": "13.43.0", "timezone": "UTC", "locale": "en" },
  "git": { "branch": "master", "commit": { "id": "49689af" } },
  "php": { "version": "8.4.27", "sapi": "fpm-fcgi" },
  "os": { "name": "Linux", "version": "6.8.0", "arch": "x86_64" },
  "process": { "pid": 42, "memory": { "usage": 2097152, "peak": 2097152, "limit": 134217728 } }
}
```
