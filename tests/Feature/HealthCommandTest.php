<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Nwidart\Actuator\Health\Indicators\PingHealthIndicator;
use Nwidart\Actuator\Tests\Fixtures\CountingHealthIndicator;
use Nwidart\Actuator\Tests\Fixtures\FixedHealthIndicator;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class HealthCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CountingHealthIndicator::$runs = 0;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.health.indicators', [
            'ping' => PingHealthIndicator::class,
            'fixed' => FixedHealthIndicator::class,
        ]);
        $app['config']->set('actuator.health.groups', [
            'liveness' => ['include' => ['ping']],
        ]);
    }

    #[Test]
    public function it_prints_the_status_and_exits_0_when_up(): void
    {
        $this->artisan('actuator:health')
            ->expectsOutput('UP')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_exits_1_when_down(): void
    {
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'DOWN']]);

        $this->artisan('actuator:health')
            ->expectsOutput('DOWN')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_exits_0_for_a_status_that_maps_to_a_2xx(): void
    {
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'WARNING']]);

        $this->artisan('actuator:health')
            ->expectsOutput('WARNING')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_follows_the_http_mapping(): void
    {
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'WARNING']]);
        config()->set('actuator.health.status.http_mapping', ['WARNING' => 500]);

        $this->artisan('actuator:health')->assertExitCode(1);
    }

    #[Test]
    public function it_follows_the_http_mapping_of_the_group(): void
    {
        config()->set('actuator.health.status.http_mapping', ['UP' => 500]);
        config()->set('actuator.health.groups.liveness.status.http_mapping', ['UP' => 200]);

        $this->artisan('actuator:health')->assertExitCode(1);
        $this->artisan('actuator:health', ['path' => 'liveness'])->assertExitCode(0);
    }

    #[Test]
    public function it_checks_a_group(): void
    {
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'DOWN']]);

        $this->artisan('actuator:health', ['path' => 'liveness'])
            ->expectsOutput('UP')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_checks_a_component_and_a_component_of_a_group(): void
    {
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'DOWN']]);

        $this->artisan('actuator:health', ['path' => 'fixed'])->expectsOutput('DOWN')->assertExitCode(1);
        $this->artisan('actuator:health', ['path' => 'liveness/ping'])->expectsOutput('UP')->assertExitCode(0);
    }

    #[Test]
    public function it_exits_2_for_an_unknown_path(): void
    {
        $this->artisan('actuator:health', ['path' => 'nope'])
            ->expectsOutputToContain('No health for path [nope].')
            ->assertExitCode(2);
        $this->artisan('actuator:health', ['path' => 'liveness/fixed'])->assertExitCode(2);
    }

    #[Test]
    public function it_prints_the_components_and_details(): void
    {
        $this->artisan('actuator:health', ['--details' => true])
            ->expectsOutput('UP')
            ->expectsOutput('  ping: UP')
            ->expectsOutput('  fixed: UP')
            ->expectsOutputToContain('    key: value')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_prints_the_details_of_a_component(): void
    {
        $this->artisan('actuator:health', ['path' => 'fixed', '--details' => true])
            ->expectsOutput('UP')
            ->expectsOutput('  key: value')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_prints_nested_details_as_json(): void
    {
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['details' => ['disk' => ['free' => 10]]]]);

        $this->artisan('actuator:health', ['path' => 'fixed', '--details' => true])
            ->expectsOutput('  disk: {"free":10}')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_prints_the_same_json_as_the_endpoint(): void
    {
        config()->set('actuator.health.show_details', 'always');
        $endpoint = $this->getJson('/actuator/health/liveness')->json();

        config()->set('actuator.health.show_details', 'never');
        $this->assertSame(0, Artisan::call('actuator:health', ['path' => 'liveness', '--json' => true]));
        $command = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['status', 'components'], array_keys($command));
        $this->assertSame(array_keys($endpoint['components']['ping']), array_keys($command['components']['ping']));
        unset($endpoint['components']['ping']['details']['duration'], $command['components']['ping']['details']['duration']);
        $this->assertSame($endpoint, $command);
    }

    #[Test]
    public function it_shows_details_although_the_endpoint_hides_them(): void
    {
        config()->set('actuator.health.show_details', 'never');
        config()->set('actuator.health.show_components', 'never');

        $this->getJson('/actuator/health')->assertExactJson(['status' => 'UP', 'groups' => ['liveness']]);
        $this->getJson('/actuator/health/fixed')->assertNotFound();

        Artisan::call('actuator:health', ['--json' => true]);
        $this->assertSame(['key' => 'value'], array_diff_key(json_decode(Artisan::output(), true)['components']['fixed']['details'], ['duration' => true]));

        $this->artisan('actuator:health', ['path' => 'fixed'])->expectsOutput('UP')->assertExitCode(0);
    }

    #[Test]
    public function it_bypasses_the_cache(): void
    {
        config()->set('actuator.health.indicators', ['counting' => CountingHealthIndicator::class]);
        config()->set('actuator.health.groups', []);
        config()->set('actuator.health.cache', ['ttl' => 60, 'store' => 'array']);
        Cache::store('array')->flush();

        $this->getJson('/actuator/health');
        $this->artisan('actuator:health')->assertExitCode(0);
        $this->artisan('actuator:health')->assertExitCode(0);
        $this->getJson('/actuator/health');

        $this->assertSame(3, CountingHealthIndicator::$runs);
    }
}
