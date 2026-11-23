<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nwidart\Actuator\Health\HealthEndpoint;
use Nwidart\Actuator\Tests\Fixtures\CountingHealthIndicator;
use Nwidart\Actuator\Tests\Fixtures\FixedHealthIndicator;
use Nwidart\Actuator\Tests\Fixtures\UnserializableHealthIndicator;
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
        Cache::store('file')->flush();
    }

    protected function tearDown(): void
    {
        Cache::store('file')->flush();

        parent::tearDown();
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
    public function a_ttl_that_is_not_a_whole_number_of_seconds_turns_the_cache_off_with_a_warning(): void
    {
        config()->set('actuator.health.cache.ttl', '3s');
        Log::spy();

        $this->getJson('/actuator/health')->assertOk()->assertJsonPath('components.counting.details.run', 1);
        $this->getJson('/actuator/health')->assertOk()->assertJsonPath('components.counting.details.run', 2);
        $this->getJson('/actuator/health/missing')->assertNotFound();

        Log::shouldHaveReceived('warning')
            ->with('Health setting [actuator.health.cache.ttl] must be a whole number of seconds, 0 or more. The health cache is off.', [])
            ->once();
    }

    #[Test]
    public function an_empty_store_name_is_the_file_store_not_the_default_store(): void
    {
        config()->set('cache.default', 'missing');

        foreach ([null, ''] as $store) {
            config()->set('actuator.health.cache.store', $store);
            Cache::store('file')->flush();
            CountingHealthIndicator::$runs = 0;

            $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 1);
            $this->getJson('/actuator/health')->assertJsonPath('components.counting.details.run', 1);
        }
    }

    #[Test]
    public function an_indicator_duration_detail_wins(): void
    {
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['details' => ['duration' => 'mine']]]);

        $this->getJson('/actuator/health')->assertJsonPath('components.fixed.details', ['duration' => 'mine']);
    }

    #[Test]
    public function a_result_the_store_cannot_serialize_is_still_answered(): void
    {
        config()->set('actuator.health.cache.store', 'file');
        config()->set('actuator.health.indicators.unserializable', UnserializableHealthIndicator::class);
        Log::spy();

        $this->getJson('/actuator/health')
            ->assertOk()
            ->assertJsonPath('components.unserializable.status', 'UP')
            ->assertJsonPath('components.unserializable.details.key', 'value');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Health cache could not be written for indicator [unserializable]'))->once();
    }

    #[Test]
    public function a_write_failure_does_not_silence_a_read_failure(): void
    {
        config()->set('actuator.health.cache.store', 'file');
        config()->set('actuator.health.indicators.unserializable', UnserializableHealthIndicator::class);
        Log::spy();

        $this->getJson('/actuator/health')->assertOk();
        config()->set('actuator.health.cache.store', 'missing');
        $this->getJson('/actuator/health')->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Health cache could not be written'))->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Health cache could not be read'))->once();
    }

    #[Test]
    public function a_store_name_that_is_not_a_string_turns_the_cache_off_with_a_warning(): void
    {
        config()->set('actuator.health.cache.store', ['file']);
        Log::spy();

        $this->getJson('/actuator/health')->assertOk()->assertJsonPath('components.counting.details.run', 1);
        $this->getJson('/actuator/health')->assertOk()->assertJsonPath('components.counting.details.run', 2);

        Log::shouldHaveReceived('warning')
            ->with('Health setting [actuator.health.cache.store] must be a cache store name. The health cache is off.', [])
            ->once();
    }

    #[Test]
    public function a_result_cached_with_details_hidden_shows_them_later(): void
    {
        config()->set('actuator.health.show_details', 'never');
        config()->set('actuator.health.show_components', 'always');
        $this->getJson('/actuator/health')->assertJsonPath('components.counting', ['status' => 'UP']);

        config()->set('actuator.health.show_details', 'always');
        $this->getJson('/actuator/health')
            ->assertJsonPath('components.counting.details.run', 1)
            ->assertJsonPath('components.counting.details.duration', fn (string $duration): bool => str_starts_with($duration, 'PT'));
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
    public function a_cache_that_fails_falls_back_to_running_the_indicator_and_logs_once(): void
    {
        config()->set('actuator.health.cache.store', 'missing');
        config()->set('actuator.health.indicators.other', [CountingHealthIndicator::class, ['status' => 'UP']]);
        Log::spy();

        $this->getJson('/actuator/health')->assertOk()->assertJsonPath('components.counting.details.run', 1);
        $this->getJson('/actuator/health')->assertOk()->assertJsonPath('components.counting.details.run', 3);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Health cache'))->once();
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
