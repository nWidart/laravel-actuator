---
title: Customise the info endpoint
description: Add your own section to the info endpoint, remove built-in sections, or point the git contributor at another directory.
---

The `info` endpoint is the merge of its contributors. Each key of `info.contributors` becomes a
top-level key of the JSON. Expose the endpoint first, see
[expose endpoints](/laravel-actuator/guides/expose-endpoints/).

## Add a section

Implement `Nwidart\Actuator\Info\InfoContributor` and return an array:

```php title="app/Info/FeatureFlagsInfoContributor.php"
<?php

namespace App\Info;

use App\Features\FeatureFlags;
use Nwidart\Actuator\Info\InfoContributor;

final class FeatureFlagsInfoContributor implements InfoContributor
{
    public function __construct(private FeatureFlags $flags) {}

    public function contribute(): array
    {
        return ['enabled' => $this->flags->enabled()];
    }
}
```

Register it under the key you want in the JSON:

```php title="config/actuator.php"
'info' => [
    'middleware' => [],

    'contributors' => [
        'app' => AppInfoContributor::class,
        // ...
        'features' => App\Info\FeatureFlagsInfoContributor::class,
    ],
],
```

```json
{
  "app": { "name": "Laravel", "environment": "production" },
  "features": { "enabled": ["new-checkout", "dark-mode"] }
}
```

The container builds the contributor, so constructor dependencies are injected. Like indicators, a
contributor entry can pass constructor arguments: `[Contributor::class, ['name' => 'value']]`.

## Omit a section conditionally

Return an empty array, and the key is left out of the response. The built-in `build` contributor does
this when the build info file is absent.

## Remove a built-in section

Delete the entry. For example, to hide the operating system and the process:

```php title="config/actuator.php" del={6-7}
'contributors' => [
    'app' => AppInfoContributor::class,
    'build' => BuildInfoContributor::class,
    'git' => GitInfoContributor::class,
    'php' => PhpInfoContributor::class,
    'os' => OsInfoContributor::class,
    'process' => ProcessInfoContributor::class,
],
```

## Read git data from another directory

`GitInfoContributor` reads `base_path('.git')`. Pass `directory` to read another one:

```php title="config/actuator.php"
'git' => [GitInfoContributor::class, ['directory' => '/srv/release/.git']],
```

## Keep the response safe

Everyone who can reach `info` sees its content. Return versions and identifiers, never secrets.
Protect the endpoint as described in [secure the endpoints](/laravel-actuator/guides/secure-endpoints/).

## See also

- [Add build and version information](/laravel-actuator/guides/add-build-info/)
- [Info contributors reference](/laravel-actuator/reference/info-contributors/)
