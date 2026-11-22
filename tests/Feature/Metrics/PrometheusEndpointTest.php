<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Metrics;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Route;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Actuator\Metrics\MetricsStore;
use Nwidart\Actuator\Tests\Fixtures\FailingMetricsStore;
use Nwidart\Actuator\Tests\TestCase;
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

    protected function metricsDisabled($app): void
    {
        $app['config']->set('actuator.metrics.enabled', false);
    }

    protected function withoutHttpMeter($app): void
    {
        $app['config']->set('actuator.metrics.enable', ['http' => false, 'php' => false]);
    }

    protected function withCommonTags($app): void
    {
        $app['config']->set('actuator.metrics.tags', ['application' => 'shop']);
    }

    protected function withoutBuckets($app): void
    {
        $app['config']->set('actuator.metrics.distribution.slo', ['http.server.requests' => []]);
    }

    protected function protectedByAuth($app): void
    {
        $app['config']->set('actuator.prometheus.middleware', ['auth']);
    }

    protected function failingStore($app): void
    {
        $app['config']->set('actuator.metrics.store', FailingMetricsStore::class);
    }

    private function scrape(): string
    {
        $response = $this->get('/actuator/prometheus')->assertOk();

        return (string) $response->getContent();
    }

    #[Test]
    public function it_answers_in_the_prometheus_text_format(): void
    {
        $response = $this->get('/actuator/prometheus')->assertOk();

        $this->assertSame('text/plain; version=0.0.4; charset=utf-8', $response->headers->get('Content-Type'));
        $response->assertSee('# TYPE php_info gauge', false);
        $response->assertSee('php_info{sapi="cli",version="'.PHP_VERSION.'"} 1', false);
        $response->assertSee('laravel_info{version="'.$this->app->version().'"} 1', false);
        $response->assertSee('# TYPE disk_free_bytes gauge', false);
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
    public function requests_are_counted_by_route_pattern(): void
    {
        Route::get('users/{user}', fn () => 'ok');

        $this->get('/users/1')->assertOk();
        $this->get('/users/2')->assertOk();
        $this->post('/users/3')->assertStatus(405);

        $metrics = $this->scrape();

        $this->assertStringContainsString('# HELP http_server_requests_seconds Duration of HTTP server request handling.', $metrics);
        $this->assertStringContainsString('# TYPE http_server_requests_seconds histogram', $metrics);
        $this->assertStringContainsString('http_server_requests_seconds_count{exception="none",method="GET",outcome="SUCCESS",status="200",uri="/users/{user}"} 2', $metrics);
        $this->assertStringContainsString('http_server_requests_seconds_bucket{exception="none",method="GET",outcome="SUCCESS",status="200",uri="/users/{user}",le="+Inf"} 2', $metrics);
        $this->assertStringContainsString('http_server_requests_seconds_bucket{exception="none",method="GET",outcome="SUCCESS",status="200",uri="/users/{user}",le="10"} 2', $metrics);
        $this->assertStringContainsString('exception="MethodNotAllowedHttpException",method="POST",outcome="CLIENT_ERROR",status="405",uri="UNKNOWN"} 1', $metrics);
        $this->assertStringNotContainsString('/users/1', $metrics);
    }

    #[Test]
    public function unknown_paths_and_methods_do_not_create_series_of_their_own(): void
    {
        $this->get('/missing/1')->assertNotFound();
        $this->get('/missing/2')->assertNotFound();
        $this->call('PROPFIND', '/missing/3')->assertNotFound();

        $metrics = $this->scrape();

        $this->assertStringContainsString('http_server_requests_seconds_count{exception="NotFoundHttpException",method="GET",outcome="CLIENT_ERROR",status="404",uri="NOT_FOUND"} 2', $metrics);
        $this->assertStringContainsString('http_server_requests_seconds_count{exception="NotFoundHttpException",method="OTHER",outcome="CLIENT_ERROR",status="404",uri="NOT_FOUND"} 1', $metrics);
        $this->assertStringNotContainsString('missing', $metrics);
    }

    #[Test]
    public function a_failed_request_is_tagged_with_its_exception(): void
    {
        Route::get('boom', fn () => throw new RuntimeException('boom'));

        $this->get('/boom')->assertStatus(500);

        $this->assertStringContainsString(
            'http_server_requests_seconds_count{exception="RuntimeException",method="GET",outcome="SERVER_ERROR",status="500",uri="/boom"} 1',
            $this->scrape(),
        );
    }

    #[Test]
    public function actuator_requests_are_not_counted(): void
    {
        $this->get('/actuator/health')->assertOk();
        $this->scrape();

        $this->assertStringNotContainsString('http_server_requests', $this->scrape());
    }

    #[Test]
    public function a_package_adds_a_tag_to_the_request_meter(): void
    {
        Route::get('blog/posts', fn () => 'ok')->name('blog.posts');
        Route::get('home', fn () => 'ok');

        $this->app->make(MeterRegistry::class)->tagUsing(
            'http.server.requests',
            static fn (RequestHandled $event): array => ['module' => str_starts_with((string) $event->request->route()?->getName(), 'blog.') ? 'Blog' : 'none'],
        );

        $this->get('/blog/posts')->assertOk();
        $this->get('/home')->assertOk();

        $metrics = $this->scrape();

        $this->assertStringContainsString('method="GET",module="Blog",outcome="SUCCESS",status="200",uri="/blog/posts"} 1', $metrics);
        $this->assertStringContainsString('method="GET",module="none",outcome="SUCCESS",status="200",uri="/home"} 1', $metrics);
    }

    #[Test]
    public function a_broken_tag_contributor_does_not_fail_the_request(): void
    {
        Route::get('home', fn () => 'ok');

        $this->app->make(MeterRegistry::class)->tagUsing('http.server.requests', static fn () => throw new RuntimeException('broken'));

        $this->get('/home')->assertOk();

        $this->assertStringContainsString('status="200",uri="/home"} 1', $this->scrape());
    }

    #[Test]
    public function a_tag_contributor_that_returns_no_value_does_not_fail_the_request(): void
    {
        Route::get('home', fn () => 'ok');

        $this->app->make(MeterRegistry::class)->tagUsing('http.server.requests', static fn (): array => ['tenant' => null]);

        $this->get('/home')->assertOk();

        $this->assertStringContainsString('status="200",uri="/home"} 1', $this->scrape());
    }

    #[Test]
    public function a_meter_that_cannot_be_read_does_not_fail_the_scrape(): void
    {
        $registry = $this->app->make(MeterRegistry::class);
        $registry->collecting(static fn () => throw new RuntimeException('broken'));
        $registry->gauge('queue.size', value: static fn () => throw new RuntimeException('broken'));

        $metrics = $this->scrape();

        $this->assertStringContainsString('php_info', $metrics);
        $this->assertStringNotContainsString('queue_size', $metrics);
    }

    #[Test]
    public function an_application_meter_is_scraped(): void
    {
        Route::get('orders', function (MeterRegistry $metrics): string {
            $metrics->counter('orders.placed', ['channel' => 'web'])->increment();

            return 'ok';
        });

        $this->get('/orders')->assertOk();
        $this->get('/orders')->assertOk();

        $this->assertStringContainsString("# TYPE orders_placed_total counter\norders_placed_total{channel=\"web\"} 2\n", $this->scrape());
    }

    #[Test]
    #[DefineEnvironment('withCommonTags')]
    public function common_tags_come_from_the_config(): void
    {
        Route::get('home', fn () => 'ok');

        $this->get('/home')->assertOk();
        $metrics = $this->scrape();

        $this->assertStringContainsString('http_server_requests_seconds_count{application="shop",exception="none"', $metrics);
        $this->assertStringContainsString('php_info{application="shop",sapi="cli"', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withoutBuckets')]
    public function a_timer_without_buckets_keeps_its_count_and_sum(): void
    {
        Route::get('home', fn () => 'ok');

        $this->get('/home')->assertOk();
        $metrics = $this->scrape();

        $this->assertStringContainsString('# TYPE http_server_requests_seconds summary', $metrics);
        $this->assertStringContainsString('http_server_requests_seconds_count{', $metrics);
        $this->assertStringNotContainsString('http_server_requests_seconds_bucket', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withoutHttpMeter')]
    public function a_meter_is_turned_off_by_prefix(): void
    {
        Route::get('home', fn () => 'ok');

        $this->get('/home')->assertOk();
        $metrics = $this->scrape();

        $this->assertFalse($this->app->make('events')->hasListeners(RequestHandled::class));
        $this->assertStringNotContainsString('http_server_requests', $metrics);
        $this->assertStringNotContainsString('php_info', $metrics);
        $this->assertStringContainsString('laravel_info', $metrics);
    }

    #[Test]
    #[DefineEnvironment('metricsDisabled')]
    public function nothing_is_collected_when_metrics_are_disabled(): void
    {
        Route::get('home', fn () => 'ok');

        $this->get('/home')->assertOk();

        $this->assertSame('', $this->scrape());
        $this->assertFalse($this->app->make('events')->hasListeners(RequestHandled::class));
    }

    #[Test]
    #[DefineEnvironment('failingStore')]
    public function a_store_that_cannot_be_read_fails_the_scrape_but_not_the_application(): void
    {
        Route::get('home', fn () => 'ok');

        $this->get('/home')->assertOk();
        $this->get('/home')->assertOk();

        $response = $this->get('/actuator/prometheus')->assertStatus(503);
        $this->assertSame('text/plain; version=0.0.4; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertInstanceOf(FailingMetricsStore::class, $this->app->make(MetricsStore::class));
    }

    #[Test]
    public function the_clear_command_empties_the_store(): void
    {
        Route::get('home', fn () => 'ok');

        $this->get('/home')->assertOk();
        $this->assertStringContainsString('http_server_requests', $this->scrape());

        $this->artisan('actuator:metrics:clear')->assertSuccessful();

        $this->assertStringNotContainsString('http_server_requests', $this->scrape());
    }
}
