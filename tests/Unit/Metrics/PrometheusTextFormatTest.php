<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Unit\Metrics;

use Nwidart\Actuator\Metrics\Export\PrometheusTextFormat;
use Nwidart\Actuator\Metrics\MeterSnapshot;
use Nwidart\Actuator\Metrics\MeterType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrometheusTextFormatTest extends TestCase
{
    /**
     * @param  list<MeterSnapshot>  $meters
     */
    private function render(array $meters): string
    {
        return (new PrometheusTextFormat)->render($meters);
    }

    #[Test]
    public function no_meters_render_as_an_empty_body(): void
    {
        $this->assertSame('', $this->render([]));
    }

    #[Test]
    public function a_counter_ends_in_total(): void
    {
        $this->assertSame(
            <<<'TEXT'
            # HELP orders_placed_total Orders placed.
            # TYPE orders_placed_total counter
            orders_placed_total{channel="app"} 5
            orders_placed_total{channel="web"} 1.5

            TEXT,
            $this->render([
                new MeterSnapshot('orders.placed', MeterType::Counter, '', ['channel' => 'app'], 'Orders placed.', value: 5),
                new MeterSnapshot('orders.placed', MeterType::Counter, '', ['channel' => 'web'], 'Orders placed.', value: 1.5),
            ]),
        );
    }

    #[Test]
    public function the_unit_and_total_are_not_repeated(): void
    {
        $output = $this->render([
            new MeterSnapshot('requests.total', MeterType::Counter, '', []),
            new MeterSnapshot('traffic.total', MeterType::Counter, 'bytes', []),
            new MeterSnapshot('disk.free.bytes', MeterType::Gauge, 'bytes', []),
            new MeterSnapshot('disk.total', MeterType::Gauge, 'bytes', []),
        ]);

        $this->assertStringContainsString("\nrequests_total 0\n", $output);
        $this->assertStringContainsString("\ntraffic_bytes_total 0\n", $output);
        $this->assertStringContainsString("\ndisk_free_bytes 0\n", $output);
        $this->assertStringContainsString("\ndisk_total_bytes 0\n", $output);
    }

    #[Test]
    public function a_gauge_carries_its_unit_and_has_no_help_without_a_description(): void
    {
        $this->assertSame(
            <<<'TEXT'
            # TYPE disk_free_bytes gauge
            disk_free_bytes 1024
            # TYPE php_info gauge
            php_info{sapi="fpm-fcgi",version="8.4.1"} 1

            TEXT,
            $this->render([
                new MeterSnapshot('php.info', MeterType::Gauge, '', ['sapi' => 'fpm-fcgi', 'version' => '8.4.1'], value: 1),
                new MeterSnapshot('disk.free', MeterType::Gauge, 'bytes', [], value: 1024),
            ]),
        );
    }

    #[Test]
    public function a_timer_with_buckets_is_a_histogram_in_seconds(): void
    {
        $this->assertSame(
            <<<'TEXT'
            # HELP http_server_requests_seconds Duration of HTTP server request handling.
            # TYPE http_server_requests_seconds histogram
            http_server_requests_seconds_bucket{method="GET",le="0.1"} 2
            http_server_requests_seconds_bucket{method="GET",le="1"} 3
            http_server_requests_seconds_bucket{method="GET",le="+Inf"} 4
            http_server_requests_seconds_count{method="GET"} 4
            http_server_requests_seconds_sum{method="GET"} 7.25
            http_server_requests_seconds_bucket{method="POST",le="+Inf"} 1
            http_server_requests_seconds_count{method="POST"} 1
            http_server_requests_seconds_sum{method="POST"} 0.5

            TEXT,
            $this->render([
                new MeterSnapshot('http.server.requests', MeterType::Timer, 'seconds', ['method' => 'GET'], 'Duration of HTTP server request handling.', count: 4, sum: 7.25, buckets: [[0.1, 2.0], [1.0, 3.0]]),
                new MeterSnapshot('http.server.requests', MeterType::Timer, 'seconds', ['method' => 'POST'], 'Duration of HTTP server request handling.', count: 1, sum: 0.5),
            ]),
        );
    }

    #[Test]
    public function a_distribution_without_buckets_is_a_summary(): void
    {
        $this->assertSame(
            <<<'TEXT'
            # TYPE upload_size_bytes summary
            upload_size_bytes_count 2
            upload_size_bytes_sum 4608

            TEXT,
            $this->render([new MeterSnapshot('upload.size', MeterType::Summary, 'bytes', [], count: 2, sum: 4608)]),
        );
    }

    #[Test]
    public function names_and_label_values_are_made_safe(): void
    {
        $this->assertSame(
            <<<'TEXT'
            # HELP _5xx_errors_total Line one\nC:\\path
            # TYPE _5xx_errors_total counter
            _5xx_errors_total{job_class="App\\Jobs\\Send \"now\"\nplease"} 1

            TEXT,
            $this->render([
                new MeterSnapshot('5xx-errors', MeterType::Counter, '', ['job.class' => "App\\Jobs\\Send \"now\"\nplease"], "Line one\nC:\\path", value: 1),
            ]),
        );
    }

    #[Test]
    public function numbers_keep_every_digit(): void
    {
        $output = $this->render([
            new MeterSnapshot('a', MeterType::Gauge, '', [], value: 1234567890123456.0),
            new MeterSnapshot('b', MeterType::Gauge, '', [], value: 0.1 + 0.2),
            new MeterSnapshot('c', MeterType::Gauge, '', [], value: 1.0E+25),
            new MeterSnapshot('d', MeterType::Gauge, '', [], value: -3.0),
        ]);

        $this->assertStringContainsString("a 1234567890123456\n", $output);
        $this->assertStringContainsString("b 0.30000000000000004\n", $output);
        $this->assertStringContainsString("c 1.0e+25\n", $output);
        $this->assertStringContainsString("d -3\n", $output);
    }

    #[Test]
    public function two_tags_never_become_the_same_label(): void
    {
        $output = $this->render([
            new MeterSnapshot('jobs', MeterType::Counter, '', ['job.class' => 'a', 'job_class' => 'b'], value: 1),
            new MeterSnapshot('wait', MeterType::Timer, 'seconds', ['le' => 'mine'], count: 1, sum: 1, buckets: [[1.0, 1.0]]),
        ]);

        $this->assertStringContainsString("jobs_total{job_class=\"b\"} 1\n", $output);
        $this->assertStringContainsString("wait_seconds_bucket{le=\"1\"} 1\n", $output);
        $this->assertStringContainsString("wait_seconds_bucket{le=\"+Inf\"} 1\n", $output);
    }

    #[Test]
    public function a_sample_is_never_written_twice(): void
    {
        $output = $this->render([
            new MeterSnapshot('orders.placed', MeterType::Counter, '', ['channel' => 'web'], value: 1),
            new MeterSnapshot('orders_placed', MeterType::Counter, '', ['channel' => 'web'], value: 5),
            new MeterSnapshot('orders_placed', MeterType::Counter, '', ['channel' => 'app'], value: 2),
            new MeterSnapshot('wait', MeterType::Timer, 'seconds', [], count: 3, sum: 1),
            new MeterSnapshot('wait.seconds.count', MeterType::Gauge, '', [], value: 9),
        ]);

        $this->assertSame(1, substr_count($output, 'orders_placed_total{channel="web"}'));
        $this->assertStringContainsString("orders_placed_total{channel=\"web\"} 1\n", $output);
        $this->assertStringContainsString("orders_placed_total{channel=\"app\"} 2\n", $output);
        $this->assertSame(1, substr_count($output, "\nwait_seconds_count "));
    }

    #[Test]
    public function special_numbers_use_the_prometheus_spelling(): void
    {
        $output = $this->render([
            new MeterSnapshot('a', MeterType::Gauge, '', [], value: INF),
            new MeterSnapshot('b', MeterType::Gauge, '', [], value: -INF),
            new MeterSnapshot('c', MeterType::Gauge, '', [], value: NAN),
        ]);

        $this->assertStringContainsString("a +Inf\n", $output);
        $this->assertStringContainsString("b -Inf\n", $output);
        $this->assertStringContainsString("c NaN\n", $output);
    }
}
