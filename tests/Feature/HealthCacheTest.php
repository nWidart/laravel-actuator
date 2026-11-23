<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Nwidart\Actuator\Health\HealthEndpoint;
use Nwidart\Actuator\Tests\Fixtures\CountingHealthIndicator;
use Nwidart\Actuator\Tests\TestCase;
use Nwidart\Metrics\Facades\Metrics;
use Nwidart\Metrics\Testing\MetricsFake;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class HealthCacheTest extends TestCase
{
    private MetricsFake $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        CountingHealthIndicator::$runs = 0;
        $this->metrics = Metrics::fake();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.health.show_details', 'always');
        $app['config']->set('actuator.health.indicators', ['counting' => CountingHealthIndicator::class]);
        $app['config']->set('actuator.health.groups', ['readiness' => ['include' => ['counting']]]);
        $app['config']->set('actuator.health.cache', ['ttl' => 5, 'store' => 'array']);
    }

    protected function withHealthMetricsOff($app): void
    {
        $app['config']->set('actuator.metrics.enable', ['laravel.health' => false]);
    }

    #[Test]
    public function the_cache_is_off_by_default(): void
    {
        config()->set('actuator.health.cache.ttl', 0);

        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 1);
        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 2);
    }

    #[Test]
    public function the_cache_is_off_with_an_older_config_that_lacks_the_keys(): void
    {
        config()->set('actuator.health', array_diff_key(config('actuator.health'), ['cache' => true]));

        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 1);
        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 2);
    }

    #[Test]
    public function it_rejects_a_ttl_that_is_not_a_whole_number_of_seconds(): void
    {
        config()->set('actuator.health.cache.ttl', 'soon');

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Health setting [actuator.health.cache.ttl] must be a whole number of seconds, 0 or more.');

        $this->getJson('/actuator/health');
    }

    #[Test]
    public function a_result_is_reused_by_the_endpoint_groups_and_components(): void
    {
        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 1);
        $this->getJson('/actuator/health/readiness')->assertJsonPath('components.counting.details.run', 1);
        $this->getJson('/actuator/health/counting')->assertJsonPath('details.run', 1);
        $this->getJson('/actuator/health/readiness/counting')->assertJsonPath('details.run', 1);

        $this->assertSame(1, CountingHealthIndicator::$runs);
    }

    #[Test]
    public function a_cached_result_keeps_its_status(): void
    {
        config()->set('actuator.health.indicators.counting', [CountingHealthIndicator::class, ['status' => 'DOWN']]);

        $this->getJson('/actuator/health')->assertStatus(503)->assertJsonPath('status', 'DOWN');
        $this->getJson('/actuator/health')->assertStatus(503)->assertJsonPath('components.counting.status', 'DOWN');

        $this->assertSame(1, CountingHealthIndicator::$runs);
    }

    #[Test]
    public function a_result_expires_after_the_ttl(): void
    {
        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 1);

        $this->travel(4)->seconds();
        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 1);

        $this->travel(2)->seconds();
        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 2);
    }

    #[Test]
    public function another_definition_is_another_entry(): void
    {
        $this->getJson('/actuator/health')->assertJsonPath('components.counting.status', 'UP');

        config()->set('actuator.health.indicators.counting', [CountingHealthIndicator::class, ['status' => 'OUT_OF_SERVICE']]);

        $this->getJson('/actuator/health')
            ->assertJsonPath('components.counting.status', 'OUT_OF_SERVICE')
            ->assertJsonPath('components.counting.details.run', 2);
    }

    #[Test]
    public function a_cache_that_fails_falls_back_to_running_the_indicator(): void
    {
        config()->set('actuator.health.cache.store', 'missing');
        Log::spy();

        $this->getJson('/actuator/health')->assertOk()->assertJsonPath('components.counting.details.run', 1);
        $this->getJson('/actuator/health')->assertOk()->assertJsonPath('components.counting.details.run', 2);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Health cache'))->twice();
    }

    #[Test]
    public function the_health_can_be_evaluated_without_the_cache(): void
    {
        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 1);

        $health = $this->app->make(HealthEndpoint::class)->health(cache: false);

        $this->assertSame(['run' => 2], array_diff_key($health['components']['counting']['details'] ?? [], ['duration' => true]));
        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 1);
    }

    #[Test]
    public function the_duration_is_a_detail_shown_only_with_details(): void
    {
        $duration = $this->getJson('/actuator/health')->json('components.counting.details.duration');
        $this->assertMatchesRegularExpression('/^PT\d+(\.\d+)?S$/', $duration);

        config()->set('actuator.health.show_details', 'never');
        config()->set('actuator.health.show_components', 'always');

        $this->getJson('/actuator/health')->assertExactJson([
            'status' => 'UP',
            'components' => ['counting' => ['status' => 'UP']],
            'groups' => ['readiness'],
        ]);
    }

    #[Test]
    public function a_cached_result_keeps_the_duration_of_its_run(): void
    {
        $duration = $this->getJson('/actuator/health')->json('components.counting.details.duration');

        $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.duration', $duration);
    }

    #[Test]
    public function the_histogram_records_each_real_run_only(): void
    {
        $this->getJson('/actuator/health');
        $this->getJson('/actuator/health/readiness');

        $point = $this->point('laravel.health.indicator.duration');
        $this->assertSame(1, $point->count);
        $this->assertSame(['indicator' => 'counting'], $point->attributes->toArray());

        $this->travel(6)->seconds();
        $this->getJson('/actuator/health');

        $this->assertSame(2, $this->point('laravel.health.indicator.duration')->count);
    }

    #[Test]
    #[DefineEnvironment('withHealthMetricsOff')]
    public function the_histogram_follows_the_metrics_enable_setting(): void
    {
        $this->getJson('/actuator/health')->assertOk();

        $this->metrics->assertNotRecorded('laravel.health.indicator.duration');
    }

    private function point(string $name): HistogramDataPoint
    {
        foreach ($this->metrics->metrics() as $metric) {
            if ($metric->name === $name && $metric->data instanceof Histogram) {
                $point = [...$metric->data->dataPoints][0];
                $this->assertInstanceOf(HistogramDataPoint::class, $point);

                return $point;
            }
        }

        $this->fail("No histogram named {$name}.");
    }
}
