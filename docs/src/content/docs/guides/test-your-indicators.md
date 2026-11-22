---
title: Test health indicators and contributors
description: Unit test a custom health indicator or info contributor, and check the health endpoint in a feature test.
---

## Unit test an indicator

An indicator is a plain class. Build it, call `health()`, and check the `status` and `details`
properties:

```php title="tests/Unit/QueueBacklogHealthIndicatorTest.php"
<?php

namespace Tests\Unit;

use App\Health\QueueBacklogHealthIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nwidart\Actuator\Health\Status;
use Tests\TestCase;

final class QueueBacklogHealthIndicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_up_below_the_threshold(): void
    {
        $health = (new QueueBacklogHealthIndicator(threshold: 10))->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(['pending' => 0, 'threshold' => 10], $health->details);
    }

    public function test_it_is_down_at_the_threshold(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);

        $health = (new QueueBacklogHealthIndicator(threshold: 1))->health();

        $this->assertSame(Status::Down, $health->status);
    }
}
```

For an indicator with dependencies, resolve it from the container, or pass a fake or a mock to the
constructor.

## Unit test a contributor

```php
public function test_it_contributes_the_build_version(): void
{
    config()->set('build.version', 'v1.4.2');

    $this->assertSame(['version' => 'v1.4.2'], (new BuildInfoContributor)->contribute());
}
```

## Check the endpoint in a feature test

The health endpoint reads the indicators and visibility from the configuration on each request, so
a test can change them with `config()->set()`:

```php title="tests/Feature/HealthTest.php"
<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_the_application_is_healthy(): void
    {
        config()->set('actuator.health.show_details', 'always');

        $this->getJson('/actuator/health')
            ->assertOk()
            ->assertJsonPath('status', 'UP')
            ->assertJsonPath('components.queue.status', 'UP');
    }

    public function test_readiness_is_out_of_service_in_maintenance_mode(): void
    {
        $this->artisan('down');

        $this->getJson('/actuator/health/readiness')
            ->assertStatus(503)
            ->assertJsonPath('status', 'OUT_OF_SERVICE');

        $this->artisan('up');
    }
}
```

:::note
`exposure`, `base_path` and the `middleware` keys are read when the routes are registered, before
the test body runs. `config()->set()` in a test does not change them. Set them in `phpunit.xml`
through environment variables, such as `ACTUATOR_BASE_PATH`, or in your configuration file.
:::

## Assert on the status enum

Compare statuses with the `Status` enum, not with strings:

```php
use Nwidart\Actuator\Health\Status;

$this->assertSame(Status::OutOfService, $health->status);
$this->assertSame(503, $health->status->httpStatus());
```

## See also

- [Write a custom health indicator](/laravel-actuator/guides/write-a-health-indicator/)
- [PHP API reference](/laravel-actuator/reference/php-api/)
