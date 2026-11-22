---
title: Configure the built-in indicators
description: Enable, disable and tune the built-in health indicators, check several connections, and turn on the Redis indicator.
---

The health endpoint runs every indicator listed in `health.indicators`. Each key is the component
name in the JSON. Each value is a class name, or a class name with constructor arguments:

```php
'name' => IndicatorClass::class,
'name' => [IndicatorClass::class, ['argument' => 'value']],
```

The container builds the indicator and passes the arguments by name. The
[health indicators reference](/laravel-actuator/reference/health-indicators/) lists the arguments
of each built-in indicator.

## Remove an indicator

Delete its line, or comment it out. For example, to stop checking the cache:

```php title="config/actuator.php" del={6}
'indicators' => [
    'ping' => PingHealthIndicator::class,
    'readinessState' => ReadinessStateHealthIndicator::class,
    'diskSpace' => DiskSpaceHealthIndicator::class,
    'db' => DatabaseHealthIndicator::class,
    'cache' => CacheHealthIndicator::class,
],
```

If a [health group](/laravel-actuator/guides/define-health-groups/) still includes the removed
name, the health endpoint throws an exception. Remove the name from the group too.

## Check a specific database connection

`DatabaseHealthIndicator` checks the default connection. Pass `connection` to check another one:

```php title="config/actuator.php"
'db' => [DatabaseHealthIndicator::class, ['connection' => 'pgsql']],
```

## Check several connections

Register the same indicator under several names:

```php title="config/actuator.php"
'db' => DatabaseHealthIndicator::class,
'reportingDb' => [DatabaseHealthIndicator::class, ['connection' => 'reporting']],
'sessionsCache' => [CacheHealthIndicator::class, ['store' => 'redis']],
```

Each name becomes its own component, with its own status.

## Change the disk space path or threshold

`DiskSpaceHealthIndicator` checks the disk that holds the application, and reports `DOWN` below
10 MB of free space. Pass `path` and `threshold` (in bytes) to change this:

```php title="config/actuator.php"
'diskSpace' => [DiskSpaceHealthIndicator::class, [
    'path' => storage_path(),
    'threshold' => 1024 * 1024 * 1024, // 1 GB
]],
```

To watch a mounted volume as well, add a second entry:

```php title="config/actuator.php"
'uploadsDisk' => [DiskSpaceHealthIndicator::class, ['path' => '/mnt/uploads']],
```

A path that does not exist reports `DOWN`, with `exists: false`.

## Enable the Redis indicator

The Redis indicator is off by default, because not every application uses Redis.

1. Install a Redis client: the `phpredis` extension, or `composer require predis/predis`.
2. Uncomment the indicator, or add it:

   ```php title="config/actuator.php"
   use Nwidart\Actuator\Health\Indicators\RedisHealthIndicator;

   'redis' => RedisHealthIndicator::class,
   ```

3. To check a connection other than `default`, pass `connection`:

   ```php title="config/actuator.php"
   'redis' => [RedisHealthIndicator::class, ['connection' => 'cache']],
   ```

## Check the result

On your machine, show the details to see every indicator and its status:

```ini title=".env"
ACTUATOR_HEALTH_SHOW_DETAILS=always
```

```bash
curl http://localhost/actuator/health
```

Do not keep `always` in production. See
[show health details to authorized users](/laravel-actuator/guides/show-health-details/).

## See also

- [Write a custom health indicator](/laravel-actuator/guides/write-a-health-indicator/)
- [Health indicators reference](/laravel-actuator/reference/health-indicators/)
