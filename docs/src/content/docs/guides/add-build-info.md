---
title: Add build and version information
description: Show the version, commit and build time of a deployment in the info endpoint, also when the image has no .git directory.
---

The built-in `git` contributor reads the `.git` directory. Many deployments do not ship it: Docker
images, Laravel Vapor, or artifacts built in CI. Write the build data to a file at build time
instead. The built-in `build` contributor shows it, and the `git` contributor falls back to it.

If you published `config/actuator.php` before this contributor existed, add it to
`info.contributors`:

```php title="config/actuator.php"
'build' => \Nwidart\Actuator\Info\Contributors\BuildInfoContributor::class,
```

## Write the build info file

Run the command at build time:

```bash
php artisan actuator:build-info
```

It writes `bootstrap/cache/actuator-build.json` with four values:

| Value     | Option            | Default                                 |
| --------- | ----------------- | --------------------------------------- |
| `version` | `--build-version` | `git describe --tags --always`          |
| `time`    | `--time`          | Now, in ISO-8601 UTC                    |
| `commit`  | `--commit`        | `git rev-parse HEAD`                    |
| `branch`  | `--branch`        | The current git branch                  |

Without `git` or a repository, the git defaults are `null`. Pass the values as options instead.
`--path` writes another file. To change the file for good, set `info.build.path` in
`config/actuator.php`.

`bootstrap/cache` must be writable when the command runs, and the file must be shipped with the
application.

## In a Docker image

A Docker build context usually has no `.git` directory. Pass the values as build arguments:

```dockerfile title="Dockerfile"
ARG APP_VERSION=dev
ARG GIT_COMMIT
ARG GIT_BRANCH

COPY . .
RUN php artisan actuator:build-info \
    --build-version="$APP_VERSION" \
    --commit="$GIT_COMMIT" \
    --branch="$GIT_BRANCH"
```

```bash
docker build \
  --build-arg APP_VERSION="$(git describe --tags --always)" \
  --build-arg GIT_COMMIT="$(git rev-parse HEAD)" \
  --build-arg GIT_BRANCH="$(git rev-parse --abbrev-ref HEAD)" \
  -t my-app .
```

Run the command after `COPY`, in the same stage as the final image, so the file is in the image.

## In CI

In GitHub Actions, before the artifact or image is built:

```yaml title=".github/workflows/deploy.yml"
- run: php artisan actuator:build-info --build-version="${{ github.ref_name }}" --commit="${{ github.sha }}"
```

## Check the result

```bash
curl http://localhost/actuator/info
```

```json
{
  "app": { "name": "Laravel", "environment": "production", "laravel": "13.43.0", "timezone": "UTC", "locale": "en" },
  "build": { "version": "v1.4.2", "time": "2026-11-22T09:12:44Z", "commit": "ae06613b0c5e1d6f2a7b8c9d0e1f2a3b4c5d6e7f", "branch": "main" },
  "git": { "branch": "main", "commit": { "id": "ae06613" } },
  "php": { "version": "8.4.27", "sapi": "fpm-fcgi" }
}
```

The output above is shortened: `os` and `process` follow. Spring Boot Admin reads `build.version`
to show the version of each instance.

## See also

- [Customise the info endpoint](/laravel-actuator/guides/write-an-info-contributor/)
- [Info contributors reference](/laravel-actuator/reference/info-contributors/)
- [Info endpoint reference](/laravel-actuator/reference/endpoints/info/)
