<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Metrics;

use Illuminate\Support\Facades\Cache;
use Nwidart\Actuator\Tests\Fixtures\CountingHealthIndicator;
use Nwidart\Actuator\Tests\Fixtures\FixedHealthIndicator;
use Nwidart\Actuator\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class HealthGaugesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CountingHealthIndicator::$runs = 0;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', ['health', 'prometheus']);
    }

    protected function withCountingIndicatorAndDefaultConfig($app): void
    {
        $app['config']->set('actuator.health.indicators', ['counting' => CountingHealthIndicator::class]);
    }

    protected function withCountingIndicatorAndConfigWithoutTheKey($app): void
    {
        $this->withCountingIndicatorAndDefaultConfig($app);
        $app['config']->set('actuator.health', array_diff_key($app['config']->get('actuator.health'), ['metrics' => true]));
    }

    protected function withUpAndWarningIndicators($app): void
    {
        $app['config']->set('actuator.health.metrics', true);
        $app['config']->set('actuator.health.indicators', [
            'db' => [FixedHealthIndicator::class, ['status' => 'UP']],
            'disk' => [FixedHealthIndicator::class, ['status' => 'WARNING']],
        ]);
    }

    protected function withInvalidStatusOrder($app): void
    {
        $this->withUpAndWarningIndicators($app);
        $app['config']->set('actuator.health.status.order', ['NOPE']);
    }

    protected function withEndpointAndGroupOrders($app): void
    {
        $this->withUpAndWarningIndicators($app);
        $app['config']->set('actuator.health.status.order', ['UP', 'WARNING']);
        $app['config']->set('actuator.health.groups', [
            'readiness' => ['include' => ['db', 'disk'], 'status' => ['order' => ['WARNING', 'UP']]],
        ]);
    }

    protected function withFailingIndicators($app): void
    {
        $app['config']->set('actuator.health.metrics', true);
        $app['config']->set('actuator.health.indicators', [
            'db' => [FixedHealthIndicator::class, ['status' => 'UP']],
            'broken' => [FixedHealthIndicator::class, ['status' => 'THROW']],
            'missing' => 'App\\Health\\MissingHealthIndicator',
        ]);
    }

    protected function withHealthMetricsOff($app): void
    {
        $this->withUpAndWarningIndicators($app);
        $app['config']->set('actuator.metrics.enable', ['laravel.health' => false]);
    }

    protected function withComponentSeriesOff($app): void
    {
        $this->withUpAndWarningIndicators($app);
        $app['config']->set('actuator.metrics.enable', ['laravel.health.status' => false]);
    }

    protected function withCountingIndicator($app): void
    {
        $app['config']->set('actuator.health.metrics', true);
        $app['config']->set('actuator.health.indicators', ['counting' => CountingHealthIndicator::class]);
    }

    protected function withCountingIndicatorAndCache($app): void
    {
        $this->withCountingIndicator($app);
        $app['config']->set('actuator.health.cache', ['ttl' => 5, 'store' => 'array']);
    }

    private function scrape(): string
    {
        return (string) $this->get('/actuator/prometheus')->assertOk()->getContent();
    }

    private static function series(string $metric, string $status, ?string $component = null): string
    {
        $component = $component === null ? '' : "component=\"{$component}\",";

        return "{$metric}{{$component}otel_scope_name=\"nwidart/laravel-actuator\",status=\"{$status}\"}";
    }

    #[Test]
    #[DefineEnvironment('withCountingIndicatorAndDefaultConfig')]
    public function the_gauges_are_off_by_default(): void
    {
        $metrics = $this->scrape();

        $this->assertStringNotContainsString('laravel_health_status', $metrics);
        $this->assertStringNotContainsString('laravel_health_overall_status', $metrics);
        $this->assertSame(0, CountingHealthIndicator::$runs);
    }

    #[Test]
    #[DefineEnvironment('withCountingIndicatorAndConfigWithoutTheKey')]
    public function the_gauges_are_off_with_an_older_config_that_lacks_the_key(): void
    {
        $this->assertStringNotContainsString('laravel_health_status', $this->scrape());
        $this->assertSame(0, CountingHealthIndicator::$runs);
    }

    #[Test]
    #[DefineEnvironment('withUpAndWarningIndicators')]
    public function each_indicator_has_one_series_per_status_with_1_for_its_current_status(): void
    {
        $metrics = $this->scrape();

        $this->assertStringContainsString('# TYPE laravel_health_status gauge', $metrics);
        foreach (['DOWN' => 0, 'OUT_OF_SERVICE' => 0, 'WARNING' => 0, 'UP' => 1, 'UNKNOWN' => 0] as $status => $value) {
            $this->assertStringContainsString(self::series('laravel_health_status', $status, 'db')." {$value}\n", $metrics);
        }
        foreach (['DOWN' => 0, 'OUT_OF_SERVICE' => 0, 'WARNING' => 1, 'UP' => 0, 'UNKNOWN' => 0] as $status => $value) {
            $this->assertStringContainsString(self::series('laravel_health_status', $status, 'disk')." {$value}\n", $metrics);
        }
    }

    #[Test]
    #[DefineEnvironment('withUpAndWarningIndicators')]
    public function the_application_status_has_its_own_gauge(): void
    {
        $metrics = $this->scrape();

        $this->assertStringContainsString('# TYPE laravel_health_overall_status gauge', $metrics);
        foreach (['DOWN' => 0, 'OUT_OF_SERVICE' => 0, 'WARNING' => 1, 'UP' => 0, 'UNKNOWN' => 0] as $status => $value) {
            $this->assertStringContainsString(self::series('laravel_health_overall_status', $status)." {$value}\n", $metrics);
        }
    }

    #[Test]
    #[DefineEnvironment('withUpAndWarningIndicators')]
    public function components_are_exported_even_when_the_health_endpoint_hides_them(): void
    {
        $this->getJson('/actuator/health')->assertOk()->assertJsonMissingPath('components');

        $this->assertStringContainsString(self::series('laravel_health_status', 'UP', 'db').' 1', $this->scrape());
    }

    #[Test]
    #[DefineEnvironment('withFailingIndicators')]
    public function an_indicator_that_throws_or_cannot_be_resolved_is_down_and_the_scrape_succeeds(): void
    {
        $metrics = $this->scrape();

        $this->assertStringContainsString(self::series('laravel_health_status', 'UP', 'db').' 1', $metrics);
        $this->assertStringContainsString(self::series('laravel_health_status', 'DOWN', 'broken').' 1', $metrics);
        $this->assertStringContainsString(self::series('laravel_health_status', 'DOWN', 'missing').' 1', $metrics);
        $this->assertStringContainsString(self::series('laravel_health_overall_status', 'DOWN').' 1', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withInvalidStatusOrder')]
    public function a_health_configuration_that_throws_leaves_the_gauges_out_and_the_scrape_succeeds(): void
    {
        $metrics = $this->scrape();

        $this->assertStringNotContainsString('laravel_health_status{', $metrics);
        $this->assertStringNotContainsString('laravel_health_overall_status{', $metrics);
        $this->assertStringContainsString('laravel_info', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withEndpointAndGroupOrders')]
    public function the_application_status_follows_the_order_of_the_endpoint_not_of_a_group(): void
    {
        $metrics = $this->scrape();

        $this->assertStringContainsString(self::series('laravel_health_overall_status', 'UP').' 1', $metrics);
        $this->assertStringContainsString(self::series('laravel_health_overall_status', 'WARNING').' 0', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withHealthMetricsOff')]
    public function the_gauges_are_turned_off_by_the_laravel_health_prefix(): void
    {
        $metrics = $this->scrape();

        $this->assertStringNotContainsString('laravel_health_status', $metrics);
        $this->assertStringNotContainsString('laravel_health_overall_status', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withComponentSeriesOff')]
    public function the_component_series_can_be_turned_off_while_keeping_the_application_status(): void
    {
        $metrics = $this->scrape();

        $this->assertStringNotContainsString('laravel_health_status{', $metrics);
        $this->assertStringContainsString(self::series('laravel_health_overall_status', 'WARNING').' 1', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withCountingIndicator')]
    public function a_scrape_runs_each_indicator_once(): void
    {
        $this->scrape();
        $this->assertSame(1, CountingHealthIndicator::$runs);

        $this->scrape();
        $this->assertSame(2, CountingHealthIndicator::$runs);
    }

    #[Test]
    #[DefineEnvironment('withCountingIndicatorAndCache')]
    public function a_scrape_reuses_the_cached_health_results(): void
    {
        Cache::store('array')->flush();

        $this->getJson('/actuator/health')->assertOk();
        $this->assertSame(1, CountingHealthIndicator::$runs);

        $this->assertStringContainsString(self::series('laravel_health_status', 'UP', 'counting').' 1', $this->scrape());
        $this->assertSame(1, CountingHealthIndicator::$runs);
    }
}
