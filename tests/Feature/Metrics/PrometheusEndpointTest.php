<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Metrics;

use Illuminate\Support\Facades\Route;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Actuator\Tests\TestCase;
use Nwidart\Metrics\Facades\Metrics;
use Nwidart\MetricsStore\MetricStore;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class PrometheusEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', ['health', 'prometheus']);
    }

    protected function protectedByAuth($app): void
    {
        $app['config']->set('actuator.prometheus.middleware', ['auth']);
    }

    protected function withLabels($app): void
    {
        $app['config']->set('metrics.prometheus.labels', ['application' => 'shop']);
    }

    private function scrape(): string
    {
        return (string) $this->get('/actuator/prometheus')->assertOk()->getContent();
    }

    #[Test]
    public function it_answers_in_the_prometheus_text_format_with_the_runtime_of_the_answering_process(): void
    {
        $response = $this->get('/actuator/prometheus')->assertOk();

        $this->assertSame('text/plain; version=0.0.4; charset=utf-8', $response->headers->get('Content-Type'));
        $response->assertSee('# TYPE laravel_info gauge', false);
        $response->assertSee('laravel_version="'.$this->app->version().'"', false);
        $response->assertSee('# TYPE system_filesystem_usage_bytes gauge', false);
    }

    #[Test]
    public function it_is_listed_on_the_discovery_page(): void
    {
        $this->getJson('/actuator')->assertOk()->assertJsonPath('_links.prometheus.href', 'http://localhost/actuator/prometheus');
    }

    #[Test]
    #[DefineEnvironment('protectedByAuth')]
    public function it_obeys_its_own_middleware(): void
    {
        Route::get('login', fn () => 'login')->name('login');

        $this->getJson('/actuator/prometheus')->assertUnauthorized();
        $this->getJson('/actuator/health')->assertOk();
    }

    #[Test]
    public function requests_are_recorded_by_route_template_and_actuator_requests_are_not(): void
    {
        Route::get('users/{user}', fn () => 'ok');

        $this->get('/users/1')->assertOk();
        $this->get('/users/2')->assertOk();
        $this->getJson('/actuator/health')->assertOk();

        $metrics = $this->scrape();

        $this->assertMatchesRegularExpression('/^http_server_request_duration_seconds_count\{http_request_method="GET",http_response_status_code="200",http_route="\/users\/\{user\}",[^}]*\} 2$/m', $metrics);
        $this->assertStringNotContainsString('/users/1', $metrics);
        $this->assertStringNotContainsString('http_route="/actuator', $metrics);
    }

    #[Test]
    public function an_application_meter_is_scraped(): void
    {
        $this->app->make(MeterRegistry::class)->counter('orders.placed', ['channel' => 'web'])->increment(3);
        $this->app->make(MeterRegistry::class)->flush();

        $this->assertStringContainsString('orders_placed_total{channel="web",otel_scope_name="nwidart/laravel-actuator"} 3', $this->scrape());
    }

    #[Test]
    #[DefineEnvironment('withCallbackGauge')]
    public function a_gauge_registered_when_the_registry_is_resolved_is_read_during_the_scrape(): void
    {
        $this->assertStringContainsString('queue_size{otel_scope_name="nwidart/laravel-actuator",queue="default"} 4', $this->scrape());
    }

    protected function withCallbackGauge($app): void
    {
        $app->afterResolving(MeterRegistry::class, function (MeterRegistry $metrics): void {
            $metrics->gauge('queue.size', ['queue' => 'default'], fn () => 4);
        });
    }

    #[Test]
    #[DefineEnvironment('withLabels')]
    public function labels_from_the_metrics_config_are_added_to_every_sample(): void
    {
        $this->assertStringContainsString('application="shop"', $this->scrape());
    }

    #[Test]
    public function a_store_that_cannot_be_read_fails_the_scrape_but_not_the_application(): void
    {
        $this->app->instance(MetricStore::class, new class implements MetricStore
        {
            public function merge(iterable $batch): void
            {
                throw new RuntimeException('The store is down.');
            }

            public function collect(ResourceInfo $resource): array
            {
                throw new RuntimeException('The store is down.');
            }

            public function clear(): void {}
        });
        Route::get('home', fn () => 'ok');

        $this->get('/home')->assertOk();
        $this->get('/actuator/prometheus')->assertStatus(503);
    }

    #[Test]
    public function nothing_is_served_once_metrics_are_disabled(): void
    {
        $this->app->make(MeterRegistry::class)->counter('orders.placed')->increment(3);
        $this->app->make(MeterRegistry::class)->gauge('queue.size', [], fn () => 4);
        $this->app->make(MeterRegistry::class)->flush();
        $this->app['config']->set('metrics.enabled', false);

        $this->assertSame('', $this->scrape());
    }

    #[Test]
    public function a_store_that_cannot_be_built_answers_503_and_the_application_keeps_working(): void
    {
        $this->app['config']->set('metrics.store.driver', 'unknown');
        $this->app->forgetInstance(MetricStore::class);
        Route::get('home', fn () => 'ok');

        $this->get('/home')->assertOk();
        $this->get('/actuator/prometheus')->assertStatus(503);
    }

    #[Test]
    #[DefineEnvironment('withOrdersTurnedOff')]
    public function a_meter_turned_off_by_prefix_no_longer_serves_its_stored_series(): void
    {
        // Recorded before the deploy that turned "orders" off.
        Metrics::meter(MeterRegistry::SCOPE)->createCounter('orders.placed')->add(3);
        Metrics::meter('app')->createCounter('orders.placed')->add(2);
        Metrics::flush();

        $metrics = $this->scrape();

        $this->assertStringNotContainsString('orders_placed_total{otel_scope_name="nwidart/laravel-actuator"}', $metrics);
        $this->assertStringContainsString('orders_placed_total{otel_scope_name="app"} 2', $metrics);
    }

    protected function withOrdersTurnedOff($app): void
    {
        $app['config']->set('actuator.metrics.enable', ['orders' => false]);
    }

    #[Test]
    public function the_clear_command_empties_the_store(): void
    {
        Route::get('home', fn () => 'ok');

        $this->get('/home')->assertOk();
        $this->assertStringContainsString('http_server_request_duration', $this->scrape());

        $this->artisan('actuator:metrics:clear')->assertSuccessful();

        $this->assertStringNotContainsString('http_route="/home"', $this->scrape());
    }
}
