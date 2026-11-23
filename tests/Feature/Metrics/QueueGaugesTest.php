<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Metrics;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Actuator\Support\QueueGauges;
use Nwidart\Actuator\Tests\TestCase;
use Nwidart\Metrics\Metrics;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class QueueGaugesTest extends TestCase
{
    /** Calls to size() on the fake queues. */
    private int $reads = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', ['prometheus']);
    }

    protected function withQueues($app): void
    {
        $app['config']->set('actuator.queue.queues', ['redis' => ['default', 'emails'], 'sqs' => ['reports']]);
    }

    protected function withQueueMetrics($app): void
    {
        $this->withQueues($app);
        $app['config']->set('actuator.queue.metrics', true);
    }

    protected function withConfigWithoutTheKey($app): void
    {
        $this->withQueues($app);
        $app['config']->set('actuator.queue', array_diff_key($app['config']->get('actuator.queue'), ['metrics' => true]));
    }

    protected function withQueueMetricsOff($app): void
    {
        $this->withQueueMetrics($app);
        $app['config']->set('actuator.metrics.enable', ['laravel.queue' => false]);
    }

    protected function withJobsSeriesOff($app): void
    {
        $this->withQueueMetrics($app);
        $app['config']->set('actuator.metrics.enable', ['laravel.queue.jobs' => false]);
    }

    protected function withBadlyShapedQueues($app): void
    {
        $this->withQueueMetrics($app);
        $app['config']->set('actuator.queue.queues', ['default', 'emails']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeQueues([
            'redis' => $this->counted(['default' => [12, 10, 1, 1], 'emails' => [3, 3, 0, 0]]),
            'sqs' => $this->sized(['reports' => 5]),
        ]);
        $this->fakeFailer(4);
    }

    private function scrape(): string
    {
        return (string) $this->get('/actuator/prometheus')->assertOk()->getContent();
    }

    private static function sizeSeries(string $connection, string $queue): string
    {
        return "laravel_queue_size{connection=\"{$connection}\",otel_scope_name=\"nwidart/laravel-actuator\",queue=\"{$queue}\"}";
    }

    private static function jobsSeries(string $connection, string $queue, string $state): string
    {
        return "laravel_queue_jobs{connection=\"{$connection}\",otel_scope_name=\"nwidart/laravel-actuator\",queue=\"{$queue}\",state=\"{$state}\"}";
    }

    private const FAILED = 'laravel_queue_failed_jobs{otel_scope_name="nwidart/laravel-actuator"}';

    #[Test]
    #[DefineEnvironment('withQueues')]
    public function the_gauges_are_off_by_default(): void
    {
        $metrics = $this->scrape();

        $this->assertStringNotContainsString('laravel_queue_', $metrics);
        $this->assertSame(0, $this->reads);
    }

    #[Test]
    #[DefineEnvironment('withConfigWithoutTheKey')]
    public function the_gauges_are_off_with_an_older_config_that_lacks_the_key(): void
    {
        $this->assertStringNotContainsString('laravel_queue_', $this->scrape());
        $this->assertSame(0, $this->reads);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function each_watched_queue_has_its_size(): void
    {
        $metrics = $this->scrape();

        $this->assertStringContainsString('# TYPE laravel_queue_size gauge', $metrics);
        $this->assertStringContainsString(self::sizeSeries('redis', 'default')." 12\n", $metrics);
        $this->assertStringContainsString(self::sizeSeries('redis', 'emails')." 3\n", $metrics);
        $this->assertStringContainsString(self::sizeSeries('sqs', 'reports')." 5\n", $metrics);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function each_queue_has_its_jobs_by_state_where_the_driver_counts_them(): void
    {
        $metrics = $this->scrape();

        $this->assertStringContainsString('# TYPE laravel_queue_jobs gauge', $metrics);
        $this->assertStringContainsString(self::jobsSeries('redis', 'default', 'pending')." 10\n", $metrics);
        $this->assertStringContainsString(self::jobsSeries('redis', 'default', 'delayed')." 1\n", $metrics);
        $this->assertStringContainsString(self::jobsSeries('redis', 'default', 'reserved')." 1\n", $metrics);
        $this->assertStringContainsString(self::jobsSeries('redis', 'emails', 'delayed')." 0\n", $metrics);
        // A driver without pendingSize(), delayedSize() and reservedSize() has only its size.
        $this->assertStringNotContainsString('laravel_queue_jobs{connection="sqs"', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function the_failed_jobs_are_counted_by_the_failer(): void
    {
        $this->assertStringContainsString(self::FAILED." 4\n", $this->scrape());
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function a_failer_that_cannot_count_leaves_the_failed_jobs_out(): void
    {
        // Such as DynamoDbFailedJobProvider.
        $this->app->instance('queue.failer', Mockery::mock(FailedJobProviderInterface::class));

        $metrics = $this->scrape();

        $this->assertStringNotContainsString('laravel_queue_failed_jobs{', $metrics);
        $this->assertStringContainsString(self::sizeSeries('redis', 'default')." 12\n", $metrics);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function a_queue_that_fails_loses_only_its_own_series_and_the_scrape_succeeds(): void
    {
        $this->fakeQueues([
            'redis' => $this->sized(['default' => 7]),
            'sqs' => $this->sized([]),
        ]);

        $metrics = $this->scrape();

        $this->assertStringContainsString(self::sizeSeries('redis', 'default')." 7\n", $metrics);
        $this->assertStringNotContainsString('queue="emails"', $metrics);
        $this->assertStringNotContainsString('connection="sqs"', $metrics);
        $this->assertStringContainsString(self::FAILED." 4\n", $metrics);
        $this->assertStringContainsString('laravel_info', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function a_connection_that_cannot_be_resolved_loses_only_its_own_series(): void
    {
        $factory = Mockery::mock(QueueFactory::class);
        $factory->allows('connection')->with('redis')->andReturn($this->sized(['default' => 7, 'emails' => 2]));
        $factory->allows('connection')->with('sqs')->andThrow(new RuntimeException('No connector for [sqs]'));
        $this->app->instance(QueueFactory::class, $factory);

        $metrics = $this->scrape();

        $this->assertStringContainsString(self::sizeSeries('redis', 'emails')." 2\n", $metrics);
        $this->assertStringNotContainsString('connection="sqs"', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function a_connection_that_fails_on_its_first_queue_is_not_tried_for_its_other_queues(): void
    {
        $this->fakeQueues([
            'redis' => $this->sized([]),
            'sqs' => $this->sized(['reports' => 5]),
        ]);

        $metrics = $this->scrape();

        // One attempt on redis, whose connect timeout each queue would otherwise wait for, one on sqs.
        $this->assertSame(2, $this->reads);
        $this->assertStringNotContainsString('connection="redis"', $metrics);
        $this->assertStringContainsString(self::sizeSeries('sqs', 'reports')." 5\n", $metrics);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function a_connection_that_cannot_be_resolved_is_resolved_once_per_scrape(): void
    {
        $factory = Mockery::mock(QueueFactory::class);
        $factory->expects('connection')->with('redis')->once()->andThrow(new RuntimeException('Connection refused'));
        $factory->allows('connection')->with('sqs')->andReturn($this->sized(['reports' => 5]));
        $this->app->instance(QueueFactory::class, $factory);

        $metrics = $this->scrape();

        $this->assertStringNotContainsString('connection="redis"', $metrics);
        $this->assertStringContainsString(self::sizeSeries('sqs', 'reports')." 5\n", $metrics);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function a_count_by_state_that_fails_keeps_the_size(): void
    {
        $this->fakeQueues([
            'redis' => new class
            {
                public function size(?string $queue = null): int
                {
                    return 6;
                }

                public function pendingSize(?string $queue = null): int
                {
                    return 5;
                }

                public function reservedSize(?string $queue = null): int
                {
                    throw new RuntimeException('READONLY');
                }
            },
            'sqs' => $this->sized(['reports' => 1]),
        ]);

        $metrics = $this->scrape();

        $this->assertStringContainsString(self::sizeSeries('redis', 'default')." 6\n", $metrics);
        $this->assertStringContainsString(self::sizeSeries('redis', 'emails')." 6\n", $metrics);
        $this->assertStringNotContainsString('laravel_queue_jobs{connection="redis"', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function a_count_the_driver_does_not_make_public_is_left_out(): void
    {
        $this->fakeQueues([
            'redis' => new class
            {
                public function size(?string $queue = null): int
                {
                    return 6;
                }

                protected function pendingSize(?string $queue = null): int
                {
                    return 5;
                }
            },
            'sqs' => $this->sized(['reports' => 1]),
        ]);

        $metrics = $this->scrape();

        $this->assertStringContainsString(self::sizeSeries('redis', 'default')." 6\n", $metrics);
        $this->assertStringNotContainsString('laravel_queue_jobs{', $metrics);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function a_scrape_reads_each_queue_once(): void
    {
        $this->scrape();
        $this->assertSame(3, $this->reads);

        $this->scrape();
        $this->assertSame(6, $this->reads);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetrics')]
    public function the_scrape_reads_the_queues_from_the_current_container(): void
    {
        // Under Octane the gauges are registered once per worker, then each request runs in a
        // clone of the application that Container::getInstance() points to.
        $sandbox = clone $this->app;
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        Container::setInstance($sandbox);
        $factory = Mockery::mock(QueueFactory::class);
        $factory->allows('connection')->andReturn($this->sized(['default' => 40, 'emails' => 41, 'reports' => 42]));
        $sandbox->instance(QueueFactory::class, $factory);
        $failer = Mockery::mock(FailedJobProviderInterface::class, CountableFailedJobProvider::class);
        $failer->allows('count')->andReturn(9);
        $sandbox->instance('queue.failer', $failer);

        try {
            $metrics = $this->scrape();
        } finally {
            Container::setInstance($this->app);
        }

        $this->assertStringContainsString(self::sizeSeries('redis', 'default')." 40\n", $metrics);
        $this->assertStringContainsString(self::sizeSeries('sqs', 'reports')." 42\n", $metrics);
        $this->assertStringContainsString(self::FAILED." 9\n", $metrics);
    }

    #[Test]
    #[DefineEnvironment('withQueueMetricsOff')]
    public function the_gauges_are_turned_off_by_the_laravel_queue_prefix(): void
    {
        $this->assertStringNotContainsString('laravel_queue_', $this->scrape());
        $this->assertSame(0, $this->reads);
    }

    #[Test]
    #[DefineEnvironment('withJobsSeriesOff')]
    public function the_jobs_by_state_can_be_turned_off_while_keeping_the_size(): void
    {
        $metrics = $this->scrape();

        $this->assertStringNotContainsString('laravel_queue_jobs', $metrics);
        $this->assertStringContainsString(self::sizeSeries('redis', 'default')." 12\n", $metrics);
        $this->assertStringContainsString(self::FAILED." 4\n", $metrics);
    }

    #[Test]
    #[DefineEnvironment('withBadlyShapedQueues')]
    public function badly_shaped_queues_leave_the_queue_gauges_out_and_the_scrape_succeeds(): void
    {
        $metrics = $this->scrape();

        $this->assertStringNotContainsString('laravel_queue_size', $metrics);
        $this->assertStringNotContainsString('laravel_queue_jobs', $metrics);
        $this->assertStringContainsString(self::FAILED." 4\n", $metrics);
        $this->assertSame(0, $this->reads);
    }

    #[Test]
    #[DefineEnvironment('withBadlyShapedQueues')]
    public function badly_shaped_queues_log_and_report_nothing_on_boot(): void
    {
        Log::spy();
        Exceptions::fake();

        // What every request runs when the application boots, scrape or not.
        QueueGauges::register($this->app, $this->app->make(MeterRegistry::class), $this->app->make(Metrics::class));

        Log::shouldNotHaveReceived('warning');
        Exceptions::assertNothingReported();
    }

    #[Test]
    #[DefineEnvironment('withBadlyShapedQueues')]
    public function badly_shaped_queues_are_reported_once_per_scrape(): void
    {
        Exceptions::fake();

        $this->scrape();
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (InvalidArgumentException $exception): bool => str_starts_with($exception->getMessage(), 'Watched queues must map a connection name to queue names'));

        $this->scrape();
        Exceptions::assertReportedCount(2);
    }

    /**
     * @param  array<string, object>  $connections  connection name => fake queue connection
     */
    private function fakeQueues(array $connections): void
    {
        $factory = Mockery::mock(QueueFactory::class);
        foreach ($connections as $name => $connection) {
            $factory->allows('connection')->with($name)->andReturn($connection);
        }

        $this->app->instance(QueueFactory::class, $factory);
    }

    private function fakeFailer(int $count): void
    {
        $failer = Mockery::mock(FailedJobProviderInterface::class, CountableFailedJobProvider::class);
        $failer->allows('count')->andReturn($count);

        $this->app->instance('queue.failer', $failer);
    }

    /**
     * A connection of a driver that only counts the size of its queues.
     *
     * @param  array<string, int>  $sizes  queue name => size
     */
    private function sized(array $sizes): object
    {
        $reads = &$this->reads;

        return new class($sizes, $reads)
        {
            /** @param  array<string, int>  $sizes */
            public function __construct(private array $sizes, private int &$reads) {}

            public function size(?string $queue = null): int
            {
                $this->reads++;

                return $this->sizes[$queue] ?? throw new RuntimeException("Queue [{$queue}] unreachable");
            }
        };
    }

    /**
     * A connection of a driver that also counts the pending, delayed and reserved jobs.
     *
     * @param  array<string, array{int, int, int, int}>  $counts  queue name => [size, pending, delayed, reserved]
     */
    private function counted(array $counts): object
    {
        $reads = &$this->reads;

        return new class($counts, $reads)
        {
            /** @param  array<string, array{int, int, int, int}>  $counts */
            public function __construct(private array $counts, private int &$reads) {}

            public function size(?string $queue = null): int
            {
                $this->reads++;

                return $this->counts[$queue][0];
            }

            public function pendingSize(?string $queue = null): int
            {
                return $this->counts[$queue][1];
            }

            public function delayedSize(?string $queue = null): int
            {
                return $this->counts[$queue][2];
            }

            public function reservedSize(?string $queue = null): int
            {
                return $this->counts[$queue][3];
            }
        };
    }
}
