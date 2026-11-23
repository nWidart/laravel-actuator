---
title: Info contributors
description: The built-in info contributors, their arguments and the fields they return.
---

All built-in contributors live in the `Nwidart\Actuator\Info\Contributors` namespace. Each is
enabled by default under the key shown.

| Default key | Class                    |
| ----------- | ------------------------ |
| `app`       | `AppInfoContributor`     |
| `build`     | `BuildInfoContributor`   |
| `git`       | `GitInfoContributor`     |
| `php`       | `PhpInfoContributor`     |
| `os`        | `OsInfoContributor`      |
| `process`   | `ProcessInfoContributor` |

## `AppInfoContributor`

| Field         | Type   | Source                       |
| ------------- | ------ | ---------------------------- |
| `name`        | string | `app.name`                   |
| `environment` | string | The application environment  |
| `laravel`     | string | The Laravel version          |
| `timezone`    | string | `app.timezone`               |
| `locale`      | string | The current locale           |

Arguments: none.

## `BuildInfoContributor`

Reads the build info file written by `php artisan actuator:build-info`. The fields follow Spring
Boot's `build` section.

If you published `config/actuator.php` before this contributor existed, add it to
`info.contributors`:

```php title="config/actuator.php"
'build' => \Nwidart\Actuator\Info\Contributors\BuildInfoContributor::class,
```

| Argument | Type           | Default           | Description               |
| -------- | -------------- | ----------------- | ------------------------- |
| `path`   | string or null | `info.build.path` | The build info file to read. |

| Field     | Type   | Source                                                         |
| --------- | ------ | -------------------------------------------------------------- |
| `version` | string | `--build-version`, or `git describe --tags --always`           |
| `time`    | string | `--time`, or the time the command ran, in ISO-8601 UTC         |
| `commit`  | string | `--commit`, or `git rev-parse HEAD`                            |
| `branch`  | string | `--branch`, or the current git branch                          |

A field without a value is left out. When the file is absent or is not valid JSON, the contributor
returns nothing and the `build` key is omitted.

## `GitInfoContributor`

Reads the current branch and commit from a `.git` directory, without running `git`.

| Argument        | Type           | Default             | Description                                   |
| --------------- | -------------- | ------------------- | --------------------------------------------- |
| `directory`     | string or null | `base_path('.git')` | The `.git` directory to read.                 |
| `buildInfoPath` | string or null | `info.build.path`   | The build info file read without `.git`.      |

| Field       | Type   | Present when                                                   |
| ----------- | ------ | -------------------------------------------------------------- |
| `branch`    | string | `HEAD` points to a ref. Branch name without `refs/heads/`.     |
| `commit.id` | string | The commit is found. First 7 characters of the commit hash.    |

The commit is read from the loose ref file, then from `packed-refs`. With a detached `HEAD`, only
`commit.id` is present. Without a readable `HEAD` file, the contributor reads `branch` and `commit` from
the build info file, with the commit cut to 7 characters. Without either, it returns nothing and the
`git` key is omitted.

## `PhpInfoContributor`

| Field     | Type   | Source                                     |
| --------- | ------ | ------------------------------------------ |
| `version` | string | `PHP_VERSION`, for example `8.4.27`        |
| `sapi`    | string | `PHP_SAPI`, for example `fpm-fcgi`, `cli`, `frankenphp` |

Arguments: none.

## `OsInfoContributor`

| Field     | Type   | Source                                          |
| --------- | ------ | ----------------------------------------------- |
| `name`    | string | `PHP_OS_FAMILY`, for example `Linux`, `Darwin`, `Windows` |
| `version` | string | Kernel release, `php_uname('r')`                |
| `arch`    | string | Machine type, `php_uname('m')`, for example `x86_64`, `arm64` |

Arguments: none.

## `ProcessInfoContributor`

Describes the PHP process that handles the request.

| Field          | Type    | Source                                                  |
| -------------- | ------- | ------------------------------------------------------- |
| `pid`          | integer | Process ID                                              |
| `memory.usage` | integer | `memory_get_usage(true)`, in bytes                      |
| `memory.peak`  | integer | `memory_get_peak_usage(true)`, in bytes                 |
| `memory.limit` | integer | `memory_limit` in bytes. `-1` means unlimited.          |

Arguments: none.
