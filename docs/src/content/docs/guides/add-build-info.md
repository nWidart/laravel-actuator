---
title: Add build and version information
description: Show the version, commit and build time of a deployment in the info endpoint, also when the image has no .git directory.
---

The built-in `git` contributor reads the `.git` directory. Many deployments do not ship it: Docker
images, Laravel Vapor, or artifacts built in CI. Pass the build data through the environment
instead, and show it with a contributor.

## Pass the build data to the application

Set environment variables at build time. For a Docker image:

```dockerfile title="Dockerfile"
ARG APP_VERSION=dev
ARG GIT_COMMIT=unknown
ARG BUILD_TIME
ENV APP_VERSION=$APP_VERSION \
    GIT_COMMIT=$GIT_COMMIT \
    BUILD_TIME=$BUILD_TIME
```

```bash
docker build \
  --build-arg APP_VERSION="$(git describe --tags --always)" \
  --build-arg GIT_COMMIT="$(git rev-parse HEAD)" \
  --build-arg BUILD_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  -t my-app .
```

In GitHub Actions, the commit is `${{ github.sha }}` and the tag is `${{ github.ref_name }}`.

## Read the data in configuration

Read environment variables in a configuration file, not in the contributor. `env()` returns `null`
once the configuration is cached:

```php title="config/build.php"
<?php

return [
    'version' => env('APP_VERSION', 'dev'),
    'commit' => env('GIT_COMMIT'),
    'time' => env('BUILD_TIME'),
];
```

## Write the contributors

A `build` section, like the one Spring Boot shows:

```php title="app/Info/BuildInfoContributor.php"
<?php

namespace App\Info;

use Nwidart\Actuator\Info\InfoContributor;

final class BuildInfoContributor implements InfoContributor
{
    public function contribute(): array
    {
        return array_filter([
            'version' => config('build.version'),
            'time' => config('build.time'),
        ]);
    }
}
```

A replacement for the `git` section that reads the commit from configuration:

```php title="app/Info/EnvGitInfoContributor.php"
<?php

namespace App\Info;

use Nwidart\Actuator\Info\InfoContributor;

final class EnvGitInfoContributor implements InfoContributor
{
    public function contribute(): array
    {
        $commit = config('build.commit');

        return $commit === null ? [] : ['commit' => ['id' => substr($commit, 0, 7)]];
    }
}
```

## Register the contributors

```php title="config/actuator.php"
'contributors' => [
    'app' => AppInfoContributor::class,
    'build' => App\Info\BuildInfoContributor::class,
    'git' => App\Info\EnvGitInfoContributor::class,
    'php' => PhpInfoContributor::class,
    'os' => OsInfoContributor::class,
    'process' => ProcessInfoContributor::class,
],
```

## Check the result

```bash
curl http://localhost/actuator/info
```

```json
{
  "app": { "name": "Laravel", "environment": "production", "laravel": "13.43.0", "timezone": "UTC", "locale": "en" },
  "build": { "version": "v1.4.2", "time": "2026-11-22T09:12:44Z" },
  "git": { "commit": { "id": "ae06613" } },
  "php": { "version": "8.4.27", "sapi": "fpm-fcgi" }
}
```

The output above is shortened: `os` and `process` follow. Spring Boot Admin reads `build.version`
to show the version of each instance.

## See also

- [Customise the info endpoint](/laravel-actuator/guides/write-an-info-contributor/)
- [Info endpoint reference](/laravel-actuator/reference/endpoints/info/)
