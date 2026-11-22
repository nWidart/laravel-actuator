---
title: Change the base path
description: Serve the actuator endpoints under a prefix other than /actuator.
---

Every actuator route lives under the `base_path` prefix, `actuator` by default.

## Change the prefix

Set `ACTUATOR_BASE_PATH` in the environment:

```ini title=".env"
ACTUATOR_BASE_PATH=manage
```

Or set `base_path` in the published configuration file:

```php title="config/actuator.php"
'base_path' => env('ACTUATOR_BASE_PATH', 'manage'),
```

The endpoints move to `/manage`, `/manage/health`, `/manage/info` and so on. The old `/actuator`
paths return 404.

Nested prefixes work too, for example `internal/actuator`.

Update everything that calls the old paths: probe definitions, load balancer health checks and
monitoring tools.

## Keep maintenance mode working

The package lets requests under `base_path` through during maintenance mode, so the readiness
probe can report `OUT_OF_SERVICE`. This needs a prefix.

:::caution[Do not use an empty base path]
With an empty `base_path`, the endpoints live at the root (`/health`, `/info`), and the discovery
endpoint takes `/`, the path of your home page. The endpoints also answer with Laravel's maintenance
page during `php artisan down`, like any other route, so the readiness probe receives an HTML 503
instead of JSON.
:::

## Rebuild cached routes

If you cache routes, rebuild the cache after the change:

```bash
php artisan route:clear
php artisan route:cache
```

## See also

- [`base_path` configuration](/laravel-actuator/reference/configuration/#base_path)
- [Liveness, readiness and maintenance mode](/laravel-actuator/concepts/liveness-and-readiness/)
