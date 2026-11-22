---
title: Write a custom endpoint
description: Add your own actuator endpoint from an application or a package, with read, write and delete operations and path selectors.
---

A custom endpoint is a class whose methods are marked as operations. You register the class under
an ID, and the endpoint then behaves like a built-in one: it lives under the base path, appears in
the discovery response, and follows the `exposure` and `middleware` configuration.

This guide builds a `queues` endpoint that lists queues, shows one queue, and clears one queue.

## Write the endpoint class

Mark each public method that serves a request with an operation attribute:

```php title="app/Actuator/QueuesEndpoint.php"
<?php

namespace App\Actuator;

use Illuminate\Contracts\Queue\Factory;
use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;

final class QueuesEndpoint
{
    private const QUEUES = ['default', 'mail'];

    public function __construct(private Factory $queues) {}

    #[ReadOperation]
    public function queues(): array
    {
        return ['queues' => self::QUEUES];
    }
}
```

The container builds the class on each request, so constructor dependencies are injected. The class
needs no interface and no base class.

## Register the endpoint

Call `Actuator::endpoint()` with an ID and the class, in the `register()` method of a service
provider:

```php title="app/Providers/AppServiceProvider.php"
use App\Actuator\QueuesEndpoint;
use Nwidart\Actuator\Actuator;

public function register(): void
{
    Actuator::endpoint('queues', QueuesEndpoint::class);
}
```

The ID is the path segment after the base path, here `/actuator/queues`. It must start with a
lowercase letter and contain only letters and digits.

## Expose the endpoint

A custom endpoint is not exposed by default. Add its ID to `exposure.include`, or use `*`:

```php title="config/actuator.php"
'exposure' => [
    'include' => ['health', 'queues'],
    'exclude' => [],
],
```

```bash
curl http://localhost/actuator/queues
```

```json
{ "queues": ["default", "mail"] }
```

The [discovery endpoint](/laravel-actuator/reference/endpoints/discovery/) now lists a `queues`
link.

## Add a path selector

A parameter marked with `#[Selector]` is a path segment. Return `null` from a read operation to
answer HTTP 404:

```php title="app/Actuator/QueuesEndpoint.php"
use Nwidart\Actuator\Endpoints\Attributes\Selector;

#[ReadOperation]
public function queue(#[Selector] string $name): ?array
{
    if (! in_array($name, self::QUEUES, true)) {
        return null;
    }

    return ['name' => $name, 'size' => $this->queues->connection()->size($name)];
}
```

```bash
curl http://localhost/actuator/queues/mail
```

```json
{ "name": "mail", "size": 12 }
```

The discovery response gets a templated `queues-name` link with the href
`http://localhost/actuator/queues/{name}`.

## Add a write or delete operation

`#[WriteOperation]` answers `POST` and `#[DeleteOperation]` answers `DELETE`. An operation that
returns nothing answers HTTP 204:

```php title="app/Actuator/QueuesEndpoint.php"
use Illuminate\Support\Facades\Artisan;
use Nwidart\Actuator\Endpoints\Attributes\DeleteOperation;

#[DeleteOperation]
public function clear(#[Selector] string $name): void
{
    Artisan::call('queue:clear', ['--queue' => $name, '--force' => true]);
}
```

```bash
curl -X DELETE http://localhost/actuator/queues/mail
```

:::danger
A write or delete operation changes your application, and actuator routes have no authentication by
default. Protect the endpoint with middleware before you expose it.
:::

## Read the request

A parameter that is not a selector must have a class type. The container resolves it, so you can
ask for the request or for a service. Use the request to read and validate input, and to find the
current user:

```php title="app/Actuator/QueuesEndpoint.php"
use Illuminate\Http\Request;
use Nwidart\Actuator\Endpoints\Attributes\WriteOperation;

#[WriteOperation]
public function pause(Request $request, #[Selector] string $name): array
{
    $input = $request->validate(['minutes' => ['required', 'integer', 'min:1']]);

    // ...

    return ['name' => $name, 'pausedFor' => $input['minutes']];
}
```

Invalid input answers HTTP 422, as in any Laravel route.

## Protect the endpoint

A custom endpoint receives the shared `middleware`, then the middleware under its own ID. Add a key
named after the ID:

```php title="config/actuator.php"
'queues' => [
    'middleware' => ['auth:sanctum', 'can:viewActuator'],
],
```

Without the key, the endpoint has only the shared middleware. See
[secure the endpoints](/laravel-actuator/guides/secure-endpoints/).

## Return another status or format

Return a response object to control the status, the headers or the content type. It is sent as it
is:

```php
#[ReadOperation]
public function export(): Response
{
    return response($this->csv(), 200, ['Content-Type' => 'text/csv']);
}
```

## Register from a package

A package registers its endpoint the same way, from its own service provider. The application does
not edit the actuator configuration to make the endpoint exist. It only decides to expose it.

When Laravel Actuator is an optional dependency of the package, guard the call with the class that
holds it:

```php title="src/ModulesServiceProvider.php"
use Nwidart\Actuator\Actuator;

public function register(): void
{
    if (class_exists(Actuator::class)) {
        Actuator::endpoint('modules', ModulesEndpoint::class);
    }
}
```

The order of the service providers does not matter: the call works before and after the actuator
provider is registered.

## Prefer `register()` over `boot()`

`Actuator::endpoint()` works in the `register()` and in the `boot()` method of a service provider.
Call it in `register()`: the endpoint then exists when the actuator provider adds its routes,
whatever the boot order of the providers. This matters most for a package that loads other
providers itself, as a module system does, because it can change when their `boot()` runs.

The [registration rules](/laravel-actuator/reference/custom-endpoints/#registration) describe what
a call in `boot()` changes. A call after the application has booted, for example in a controller,
throws a `LogicException`.

## Test the endpoint

Call the endpoint in a feature test:

```php title="tests/Feature/QueuesEndpointTest.php"
public function test_it_lists_the_queues(): void
{
    $this->getJson('/actuator/queues')->assertOk()->assertJsonPath('queues.0', 'default');
}
```

The routes are registered when the application boots. Expose the endpoint in
`config/actuator.php`: a `config()->set()` call inside a test comes too late. A package tested with
Orchestra Testbench sets the value in `defineEnvironment()`:

```php title="tests/TestCase.php"
protected function defineEnvironment($app): void
{
    $app['config']->set('actuator.exposure.include', ['modules']);
}
```

## See also

- [Custom endpoints reference](/laravel-actuator/reference/custom-endpoints/)
- [Expose endpoints](/laravel-actuator/guides/expose-endpoints/)
- [Secure the endpoints](/laravel-actuator/guides/secure-endpoints/)
