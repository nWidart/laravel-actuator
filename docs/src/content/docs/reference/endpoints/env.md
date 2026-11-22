---
title: env
description: The env endpoint, which lists configuration keys and environment variables in the Spring Boot format, with masked values.
---

Lists every configuration key and environment variable.

| Property      | Value                                                 |
| ------------- | ----------------------------------------------------- |
| ID            | `env`                                                 |
| Path          | `GET /actuator/env`                                   |
| Route name    | `actuator.env`                                        |
| Exposure      | Not exposed by default                                |
| Middleware    | `middleware`, then `env.middleware`                   |
| Configuration | [`env`](/laravel-actuator/reference/configuration/#env) |
| Class         | `Nwidart\Actuator\Http\Controllers\EnvController`     |

:::danger
Even with masked values, the keys reveal the services and packages the application uses. With
`show_values` granted, the response holds every secret: `APP_KEY`, database passwords and API keys.
Never expose this endpoint without authentication.
:::

## Response

HTTP 200 with a JSON object:

| Key               | Type   | Description                                                           |
| ----------------- | ------ | --------------------------------------------------------------------- |
| `activeProfiles`  | array  | One entry: the application environment (`APP_ENV`).                   |
| `propertySources` | array  | Two sources, `config` then `environment`.                             |

Each property source:

| Key          | Type   | Description                                                            |
| ------------ | ------ | ---------------------------------------------------------------------- |
| `name`       | string | `config` or `environment`.                                             |
| `properties` | object | Map of key to `{"value": ...}`, sorted by key.                         |

| Source        | Keys                                                                            |
| ------------- | ------------------------------------------------------------------------------- |
| `config`      | Every configuration key, flattened with dots, for example `database.default`.   |
| `environment` | Every variable from `getenv()` and `$_ENV`. `$_ENV` wins when both define a key. |

Each `value` is the real value when `env.show_values` grants access, otherwise `******`. The keys
are always listed.

## Example

```bash
curl http://localhost/actuator/env
```

```json
{
  "activeProfiles": ["production"],
  "propertySources": [
    {
      "name": "config",
      "properties": {
        "app.debug": { "value": "******" },
        "app.key": { "value": "******" },
        "app.name": { "value": "******" }
      }
    },
    {
      "name": "environment",
      "properties": {
        "APP_ENV": { "value": "******" },
        "DB_HOST": { "value": "******" }
      }
    }
  ]
}
```

The example is shortened. A real response lists hundreds of keys.
