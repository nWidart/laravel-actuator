---
title: Monitor with Spring Boot Admin
description: Register a Laravel application with a Spring Boot Admin server, with static discovery or a scheduled registration command.
---

[Spring Boot Admin](https://github.com/codecentric/spring-boot-admin) is a dashboard for
applications that expose Spring Boot Actuator endpoints. It can monitor a Laravel application that
uses this package. The [compatibility page](/laravel-actuator/concepts/spring-boot-compatibility/)
lists which views work.

This guide assumes a running Spring Boot Admin server at `https://admin.example.com`.

## Expose and protect the endpoints

Expose the endpoints Spring Boot Admin can display:

```php title="config/actuator.php"
'exposure' => [
    'include' => ['health', 'info', 'env', 'scheduledtasks'],
    'exclude' => [],
],
```

Spring Boot Admin sends HTTP basic authentication when the instance metadata holds `user.name` and
`user.password`. Create a dedicated user for it, for example `monitoring@example.com`, and protect
the endpoints with Laravel's `auth.basic` middleware and the `viewActuator` gate:

```php title="config/actuator.php"
'info' => [
    'middleware' => ['auth.basic', 'can:viewActuator'],
    // ...
],

'env' => [
    'middleware' => ['auth.basic', 'can:viewActuator'],
    'show_values' => 'never',
],

'scheduledtasks' => [
    'middleware' => ['auth.basic', 'can:viewActuator'],
],
```

```php title="app/Providers/AppServiceProvider.php"
Gate::define('viewActuator', fn (User $user) => $user->email === 'monitoring@example.com' || $user->is_admin);
```

`auth.basic` checks the credentials against the `email` and `password` columns of your users.

Keep `health` public for your probes. Spring Boot Admin then shows the overall status. To list each
component in the dashboard, show the components without their details:

```php title="config/actuator.php"
'health' => [
    'show_details' => 'never',
    'show_components' => 'always',
    // ...
],
```

## Option 1: register with static discovery

If the Spring Boot Admin server uses Spring Cloud simple discovery, list the application in the
server's `application.yml`:

```yaml title="application.yml (Spring Boot Admin server)"
spring:
  cloud:
    discovery:
      client:
        simple:
          instances:
            billing:
              - uri: https://billing.example.com
                metadata:
                  management.context-path: /actuator
                  user.name: ${BILLING_ACTUATOR_USER}
                  user.password: ${BILLING_ACTUATOR_PASSWORD}
```

Set `management.context-path` to your [base path](/laravel-actuator/guides/change-the-base-path/).

## Option 2: register from Laravel

Spring Boot Admin also accepts registrations over HTTP at `POST /instances`. The server keeps them
in memory, so register again on a schedule. The same `healthUrl` keeps the same instance ID.

Create the command:

```php title="app/Console/Commands/RegisterWithSpringBootAdmin.php"
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class RegisterWithSpringBootAdmin extends Command
{
    protected $signature = 'actuator:register-admin';

    protected $description = 'Register this application with the Spring Boot Admin server';

    public function handle(): int
    {
        $base = rtrim(config('app.url'), '/');

        $response = Http::withBasicAuth(config('services.sba.user'), config('services.sba.password'))
            ->post(config('services.sba.url').'/instances', [
                'name' => config('app.name'),
                'serviceUrl' => $base,
                'managementUrl' => "{$base}/actuator",
                'healthUrl' => "{$base}/actuator/health",
                'metadata' => [
                    'user.name' => config('services.sba.actuator_user'),
                    'user.password' => config('services.sba.actuator_password'),
                ],
            ]);

        if ($response->failed()) {
            $this->error("Registration failed with HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $this->info('Registered as '.$response->json('id').'.');

        return self::SUCCESS;
    }
}
```

Add the settings:

```php title="config/services.php"
'sba' => [
    'url' => env('SBA_URL'),
    'user' => env('SBA_USER'),
    'password' => env('SBA_PASSWORD'),
    'actuator_user' => env('ACTUATOR_USER'),
    'actuator_password' => env('ACTUATOR_PASSWORD'),
],
```

Schedule it:

```php title="routes/console.php"
use Illuminate\Support\Facades\Schedule;

Schedule::command('actuator:register-admin')->everyMinute();
```

Drop `withBasicAuth()` if the admin server does not protect its registration endpoint.

## Check the result

Open the Spring Boot Admin dashboard. The application appears with its status. The instance page
shows the health components, the info sections, the environment and the scheduled tasks.

## See also

- [Spring Boot Actuator compatibility](/laravel-actuator/concepts/spring-boot-compatibility/)
- [Spring Boot Admin documentation](https://docs.spring-boot-admin.com/)
