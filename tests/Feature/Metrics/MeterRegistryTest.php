<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Metrics;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Actuator\Tests\TestCase;
use Nwidart\Metrics\ExportTimeMetrics;
use Nwidart\Metrics\Facades\Metrics;
use Nwidart\Metrics\Testing\MetricsFake;
use Nwidart\MetricsStore\Prometheus\PrometheusTextFormat;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;

final class MeterRegistryTest extends TestCase
{
    private MetricsFake $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metrics = Metrics::fake();
    }

    protected function withBuckets($app): void
    {
        $app['config']->set('actuator.metrics.distribution.slo', ['payment.charge' => [1, 0.25, 5]]);
    }

    protected function withPrefixes($app): void
    {
        $app['config']->set('actuator.metrics.enable', ['orders' => false, 'orders.placed' => true]);
    }

    private function registry(): MeterRegistry
    {
        return $this->app->make(MeterRegistry::class);
    }

    #[Test]
    public function a_counter_adds_up_and_other_tags_are_another_series(): void
    {
        $this->registry()->counter('orders.placed', ['channel' => 'web'])->increment();
        $this->registry()->counter('orders.placed', ['channel' => 'web'])->increment(2);
        $this->registry()->counter('orders.placed', ['channel' => 'api'])->increment();

        $this->metrics->assertValue('orders.placed', 3, ['channel' => 'web']);
        $this->metrics->assertValue('orders.placed', 1, ['channel' => 'api']);
    }

    #[Test]
    public function a_counter_cannot_go_down(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->counter('orders.placed')->increment(-1);
    }

    #[Test]
    public function a_gauge_keeps_the_last_value(): void
    {
        $this->registry()->gauge('backup.age', unit: 'seconds')->set(10);
        $this->registry()->gauge('backup.age', unit: 'seconds')->set(4);

        $this->metrics->assertValue('backup.age', 4);
    }

    #[Test]
    public function a_timer_records_seconds_and_times_a_callback_even_when_it_throws(): void
    {
        $timer = $this->registry()->timer('payment.charge', ['provider' => 'stripe']);
        $timer->record(0.5);
        $this->assertSame('receipt', $timer->time(fn () => 'receipt'));
        try {
            $timer->time(fn () => throw new RuntimeException('declined'));
        } catch (RuntimeException) {
        }

        $point = $this->point('payment.charge');
        $this->assertSame(3, $point->count);
        $this->assertGreaterThanOrEqual(0.5, $point->sum);
        // No configured bounds: only the count and the sum.
        $this->assertSame([], $point->explicitBounds);
    }

    #[Test]
    #[DefineEnvironment('withBuckets')]
    public function configured_buckets_are_sorted_and_used(): void
    {
        foreach ([0.1, 0.8, 3, 20] as $seconds) {
            $this->registry()->timer('payment.charge')->record($seconds);
        }

        $point = $this->point('payment.charge');
        $this->assertSame([0.25, 1.0, 5.0], $point->explicitBounds);
        $this->assertSame([1, 1, 1, 1], $point->bucketCounts);
    }

    #[Test]
    public function a_summary_records_amounts_in_its_own_unit(): void
    {
        $this->registry()->summary('upload.size', ['disk' => 's3'], unit: 'bytes')->record(1024);
        $this->registry()->flush();

        $output = (new PrometheusTextFormat)->render($this->metrics->metrics());
        $this->assertStringContainsString('upload_size_bytes_sum{disk="s3",otel_scope_name="nwidart/laravel-actuator"} 1024', $output);
    }

    #[Test]
    public function values_that_are_not_finite_are_ignored(): void
    {
        $this->registry()->counter('orders.placed')->increment(INF);
        $this->registry()->gauge('temperature')->set(NAN);
        $this->registry()->timer('payment.charge')->record(INF);

        $this->metrics->assertNothingRecorded();
    }

    #[Test]
    #[DefineEnvironment('withPrefixes')]
    public function meters_are_turned_off_by_the_longest_matching_prefix(): void
    {
        $registry = $this->registry();
        $this->assertFalse($registry->enabled('orders.refunded'));
        $this->assertTrue($registry->enabled('orders.placed.web'));
        $this->assertTrue($registry->enabled('ordersx'));

        $registry->counter('orders.refunded')->increment();
        $registry->counter('orders.placed')->increment();

        $this->metrics->assertNotRecorded('orders.refunded');
        $this->metrics->assertValue('orders.placed', 1);
    }

    #[Test]
    public function callbacks_are_read_on_export_and_never_stored(): void
    {
        $calls = 0;
        $this->registry()->gauge('queue.size', ['queue' => 'default'], function () use (&$calls): int {
            $calls++;

            return 4;
        });
        $this->registry()->gauge('queue.size', ['queue' => 'low'], fn () => null);
        $this->registry()->counter('mail.sent', [], fn () => throw new RuntimeException('provider down'));
        $this->registry()->counter('jobs.processed', [], fn () => 120);

        $this->metrics->assertNothingRecorded();
        $this->assertSame(0, $calls);

        $output = (new PrometheusTextFormat)->render($this->app->make(ExportTimeMetrics::class)->collect(ResourceInfoFactory::emptyResource()));
        $this->assertSame(1, $calls);
        $this->assertStringContainsString('queue_size{otel_scope_name="nwidart/laravel-actuator",queue="default"} 4', $output);
        $this->assertStringNotContainsString('queue="low"', $output);
        $this->assertStringContainsString('jobs_processed_total{otel_scope_name="nwidart/laravel-actuator"} 120', $output);
        $this->assertStringNotContainsString('mail_sent', $output);
    }

    #[Test]
    public function the_same_name_with_another_unit_is_another_metric(): void
    {
        $this->registry()->summary('payload.size', unit: 'bytes')->record(1024);
        $this->registry()->summary('payload.size', unit: 'items')->record(3);
        $this->registry()->gauge('payload.limit', unit: 'bytes')->set(2048);
        $this->registry()->gauge('payload.limit', unit: 'items')->set(10);

        $output = (new PrometheusTextFormat)->render($this->metrics->metrics());
        $this->assertStringContainsString('payload_size_bytes_sum{otel_scope_name="nwidart/laravel-actuator"} 1024', $output);
        $this->assertStringContainsString('payload_size_items_sum{otel_scope_name="nwidart/laravel-actuator"} 3', $output);
        $this->assertStringContainsString('payload_limit_bytes{otel_scope_name="nwidart/laravel-actuator"} 2048', $output);
        $this->assertStringContainsString('payload_limit_items{otel_scope_name="nwidart/laravel-actuator"} 10', $output);
    }

    #[Test]
    public function a_callback_registered_again_replaces_the_first_whatever_the_order_of_the_tags(): void
    {
        $this->registry()->gauge('queue.size', ['queue' => 'default', 'host' => 'a'], fn () => 4);
        $this->registry()->gauge('queue.size', ['host' => 'a', 'queue' => 'default'], fn () => null);

        $output = (new PrometheusTextFormat)->render($this->app->make(ExportTimeMetrics::class)->collect(ResourceInfoFactory::emptyResource()));
        $this->assertStringNotContainsString('queue_size', $output);
    }

    #[Test]
    public function a_description_is_attached_to_the_meter(): void
    {
        $this->registry()->describe('orders.placed', 'Orders placed.');
        $this->registry()->counter('orders.placed')->increment();

        $this->assertSame('Orders placed.', $this->metrics->metrics()[0]->description);
    }

    #[Test]
    public function tag_using_adds_tags_to_a_built_in_meter(): void
    {
        $this->registry()->tagUsing('db.client.operation.duration', fn (QueryExecuted $event): array => ['module' => 'blog']);

        DB::select('select 1');

        $this->metrics->assertRecorded('db.client.operation.duration', ['module' => 'blog']);
    }

    #[Test]
    public function tags_for_runs_the_contributors_of_an_application_meter(): void
    {
        $registry = $this->registry();
        $registry->tagUsing('orders.placed', fn (object $order): array => ['channel' => 'web']);

        $registry->counter('orders.placed', $registry->tagsFor('orders.placed', new stdClass))->increment();

        $this->metrics->assertValue('orders.placed', 1, ['channel' => 'web']);
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
