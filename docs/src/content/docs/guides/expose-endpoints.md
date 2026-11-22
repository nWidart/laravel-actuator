---
title: Expose endpoints
description: Choose which actuator endpoints are reachable over HTTP with the include and exclude lists.
---

Only `health` is exposed by default. An endpoint that is not exposed has no route, so it returns
HTTP 404. The [discovery endpoint](/laravel-actuator/reference/endpoints/discovery/) at `/actuator`
is always registered and lists only the exposed endpoints.

:::caution
`info`, `env`, `mappings`, `scheduledtasks` and `prometheus` reveal details about your application.
[Secure them](/laravel-actuator/guides/secure-endpoints/) before you expose them in production.
:::

Publish the configuration file first if you have not done so:

```bash
php artisan vendor:publish --tag=actuator-config
```

## Expose specific endpoints

List the endpoint IDs in `exposure.include`:

```php title="config/actuator.php"
'exposure' => [
    'include' => ['health', 'info'],
    'exclude' => [],
],
```

The built-in IDs are `health`, `info`, `env`, `mappings`, `scheduledtasks` and `prometheus`. A
[custom endpoint](/laravel-actuator/guides/write-a-custom-endpoint/) is exposed the same way, with
the ID it was registered under. The `*` wildcard includes custom endpoints.

Exposing `prometheus` gives the metrics an address. It does not start or stop their recording, see
[collect metrics with Prometheus](/laravel-actuator/guides/collect-metrics-with-prometheus/).

## Expose every endpoint

Use the `*` wildcard:

```php title="config/actuator.php"
'exposure' => [
    'include' => ['*'],
    'exclude' => [],
],
```

## Expose every endpoint except some

List the endpoints to keep closed in `exposure.exclude`. An excluded endpoint is never exposed,
even when `include` names it or uses `*`:

```php title="config/actuator.php"
'exposure' => [
    'include' => ['*'],
    'exclude' => ['env'],
],
```

## Expose different endpoints per environment

The configuration file is plain PHP, so you can read the environment. For example, expose
everything locally and only `health` and `info` elsewhere:

```php title="config/actuator.php"
'exposure' => [
    'include' => env('APP_ENV') === 'local' ? ['*'] : ['health', 'info'],
    'exclude' => [],
],
```

## Check the result

Ask the discovery endpoint which endpoints are exposed:

```bash
curl http://localhost/actuator
```

If you cache routes, an exposure change takes effect only after you rebuild the cache:

```bash
php artisan route:clear
php artisan route:cache
```

## See also

- [Secure the endpoints](/laravel-actuator/guides/secure-endpoints/)
- [`exposure` configuration](/laravel-actuator/reference/configuration/#exposure)
