---
title: Secure the endpoints
description: Protect actuator endpoints with middleware, authorize users with the viewActuator gate, and keep health public for probes.
---

Laravel Actuator registers its routes without authentication. Protection comes from two settings:

- **Middleware** decides who can reach an endpoint at all.
- **Visibility** (`show_details`, `show_components`, `show_values`) decides how much a reachable
  endpoint reveals. The `when_authorized` value checks the `viewActuator` gate.

The [security model](/laravel-actuator/concepts/security-model/) explains how the two fit together.

## Protect one endpoint, keep health public

The top-level `middleware` key applies to every actuator route, `health` included. To keep `health`
open for probes, put the protection on the other endpoints instead:

```php title="config/actuator.php"
'middleware' => [],

'health' => [
    'middleware' => [],
    // ...
],

'env' => [
    'middleware' => ['web', 'auth', 'can:viewActuator'],
    'show_values' => 'never',
],

'info' => [
    'middleware' => ['web', 'auth', 'can:viewActuator'],
    // ...
],
```

An endpoint receives the shared middleware first, then its own. Each entry is a string: a
middleware alias, a middleware group or a class name.

A [custom endpoint](/laravel-actuator/guides/write-a-custom-endpoint/) is protected the same way,
under a key named after its ID, for example `'modules' => ['middleware' => ['auth:sanctum']]`.
Without that key, it has only the shared middleware. Protect an endpoint with write or delete
operations before you expose it.

:::note
The actuator routes do not belong to the `web` group. Add `web` when you use session
authentication, or `auth` cannot find the logged-in user.
:::

## Define the viewActuator gate

The `can:viewActuator` middleware and the `when_authorized` visibility both check the
`viewActuator` gate. Define it in `AppServiceProvider::boot()`:

```php title="app/Providers/AppServiceProvider.php"
use App\Models\User;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewActuator', fn (User $user) => $user->is_admin);
}
```

When the gate is not defined, `when_authorized` never grants access.

## Authenticate machine clients with a token

Monitoring tools cannot log in through a form. For them, use token authentication.

With [Laravel Sanctum](https://laravel.com/docs/sanctum), use the `auth:sanctum` middleware and send
the token as a bearer token:

```php title="config/actuator.php"
'info' => [
    'middleware' => ['auth:sanctum', 'can:viewActuator'],
    // ...
],
```

```bash
curl -H "Authorization: Bearer $TOKEN" https://example.com/actuator/info
```

Without Sanctum, a small middleware can compare a shared secret:

```php title="app/Http/Middleware/EnsureActuatorToken.php"
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActuatorToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.actuator.token');

        abort_unless($expected !== '' && hash_equals($expected, (string) $request->bearerToken()), 401);

        return $next($request);
    }
}
```

```php title="config/services.php"
'actuator' => [
    'token' => env('ACTUATOR_TOKEN'),
],
```

```php title="config/actuator.php"
'env' => [
    'middleware' => [App\Http\Middleware\EnsureActuatorToken::class],
    'show_values' => 'never',
],
```

## Protect every endpoint

If nothing outside your network calls `health`, protect all routes at once with the top-level key.
The discovery endpoint uses it too:

```php title="config/actuator.php"
'middleware' => [App\Http\Middleware\EnsureActuatorToken::class],
```

## Limit the request rate

Each health request runs every indicator, including a database query and a cache write. Add the
`throttle` middleware to limit public traffic:

```php title="config/actuator.php"
'health' => [
    'middleware' => ['throttle:120,1'],
    // ...
],
```

Choose a limit above your probe frequency. Kubernetes and load balancers poll every few seconds
from each node.

## Restrict the network

Middleware protects the application layer. Where you can, also keep `/actuator` off the public
internet: serve it on an internal port, or block the path at your ingress or reverse proxy, and let
only the cluster and your monitoring network reach it.

## Check the result

```bash
curl -i https://example.com/actuator/env
# HTTP/1.1 401 Unauthorized
curl -i https://example.com/actuator/health
# HTTP/1.1 200 OK
```

## See also

- [Show health details to authorized users](/laravel-actuator/guides/show-health-details/)
- [Visibility values](/laravel-actuator/reference/configuration/#visibility-values)
- [Security model](/laravel-actuator/concepts/security-model/)
