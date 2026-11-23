<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Indicators;

use BadMethodCallException;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mockery;
use Nwidart\Actuator\Health\Indicators\QueueHealthIndicator;
use Nwidart\Actuator\Health\Status;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class QueueHealthIndicatorTest extends TestCase
{
    #[Test]
    public function it_reports_the_size_of_each_queue_by_connection(): void
    {
        $health = $this->indicator(['redis' => ['default', 'emails'], 'sqs' => ['reports']], [
            'redis' => $this->counted(['default' => [12, 10, 1, 1], 'emails' => [3, 3, 0, 0]]),
            'sqs' => $this->sized(['reports' => 5]),
        ])->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(['queues' => [
            'redis' => [
                'default' => ['size' => 12, 'pending' => 10, 'delayed' => 1, 'reserved' => 1],
                'emails' => ['size' => 3, 'pending' => 3, 'delayed' => 0, 'reserved' => 0],
            ],
            // A driver without pendingSize(), delayedSize() and reservedSize() shows only the size.
            'sqs' => ['reports' => ['size' => 5]],
        ]], $health->details);
    }

    #[Test]
    public function it_is_warning_from_the_warning_threshold_and_down_from_the_down_threshold(): void
    {
        $status = fn (int $size): Status => $this->indicator(['redis' => ['default']], ['redis' => $this->sized(['default' => $size])], warningThreshold: 100, downThreshold: 1000)->health()->status;

        $this->assertSame(Status::Up, $status(99));
        $this->assertSame(Status::Warning, $status(100));
        $this->assertSame(Status::Warning, $status(999));
        $this->assertSame(Status::Down, $status(1000));
    }

    #[Test]
    public function the_largest_queue_sets_the_status_and_the_thresholds_are_in_the_details(): void
    {
        $health = $this->indicator(['redis' => ['default', 'emails']], [
            'redis' => $this->sized(['default' => 1, 'emails' => 150]),
        ], warningThreshold: 100, downThreshold: 1000)->health();

        $this->assertSame(Status::Warning, $health->status);
        $this->assertSame(100, $health->details['warningThreshold']);
        $this->assertSame(1000, $health->details['downThreshold']);
    }

    #[Test]
    public function a_null_threshold_is_off(): void
    {
        $health = $this->indicator(['redis' => ['default']], ['redis' => $this->sized(['default' => PHP_INT_MAX])])->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertArrayNotHasKey('warningThreshold', $health->details);
        $this->assertArrayNotHasKey('downThreshold', $health->details);
    }

    #[Test]
    public function a_queue_that_fails_is_down_and_leaves_the_others_reported(): void
    {
        $health = $this->indicator(['redis' => ['default'], 'sqs' => ['reports']], [
            'redis' => $this->sized(['default' => 4]),
            'sqs' => $this->sized([]),
        ])->health();

        $this->assertSame(Status::Down, $health->status);
        $this->assertSame(['queues' => [
            'redis' => ['default' => ['size' => 4]],
            'sqs' => ['reports' => ['error' => 'RuntimeException: Queue [reports] unreachable']],
        ]], $health->details);
    }

    #[Test]
    public function a_connection_that_fails_on_its_first_queue_is_not_tried_for_its_other_queues(): void
    {
        $connection = new class
        {
            public int $calls = 0;

            public function size(?string $queue = null): int
            {
                $this->calls++;

                throw new RuntimeException('Connection refused');
            }
        };

        $health = $this->indicator(['redis' => ['default', 'emails', 'high', 'low']], ['redis' => $connection])->health();

        $this->assertSame(1, $connection->calls);
        $this->assertSame(Status::Down, $health->status);
        $skipped = ['error' => 'Skipped: the connection failed on queue [default]'];
        $this->assertSame(['queues' => ['redis' => [
            'default' => ['error' => 'RuntimeException: Connection refused'],
            'emails' => $skipped,
            'high' => $skipped,
            'low' => $skipped,
        ]]], $health->details);
    }

    #[Test]
    public function a_connection_that_cannot_be_made_is_made_once(): void
    {
        $factory = Mockery::mock(QueueFactory::class);
        $factory->expects('connection')->once()->with('sqs')->andThrow(new RuntimeException('No credentials'));

        $health = (new QueueHealthIndicator($factory, config(), ['sqs' => ['reports', 'emails']]))->health();

        $this->assertSame(Status::Down, $health->status);
        $this->assertSame(['queues' => ['sqs' => [
            'reports' => ['error' => 'RuntimeException: No credentials'],
            'emails' => ['error' => 'Skipped: the connection failed on queue [reports]'],
        ]]], $health->details);
    }

    #[Test]
    public function a_queue_that_fails_after_another_answered_leaves_the_next_ones_read(): void
    {
        $health = $this->indicator(['sqs' => ['reports', 'deleted', 'emails']], ['sqs' => $this->sized(['reports' => 4, 'emails' => 1])])->health();

        $this->assertSame(Status::Down, $health->status);
        $this->assertSame(['queues' => ['sqs' => [
            'reports' => ['size' => 4],
            'deleted' => ['error' => 'RuntimeException: Queue [deleted] unreachable'],
            'emails' => ['size' => 1],
        ]]], $health->details);
    }

    #[Test]
    public function a_count_by_state_that_fails_keeps_the_size(): void
    {
        $connection = new class
        {
            public function size(?string $queue = null): int
            {
                return 6;
            }

            public function pendingSize(?string $queue = null): int
            {
                throw new RuntimeException('Timed out');
            }
        };

        $health = $this->indicator(['redis' => ['default']], ['redis' => $connection])->health();

        $this->assertSame(Status::Down, $health->status);
        $this->assertSame(['queues' => ['redis' => ['default' => ['size' => 6, 'error' => 'RuntimeException: Timed out']]]], $health->details);
    }

    #[Test]
    public function a_count_the_driver_does_not_make_public_is_left_out(): void
    {
        $connection = new class
        {
            public function size(?string $queue = null): int
            {
                return 6;
            }

            protected function pendingSize(?string $queue = null): int
            {
                return 5;
            }
        };

        $health = $this->indicator(['custom' => ['default']], ['custom' => $connection])->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(['queues' => ['custom' => ['default' => ['size' => 6]]]], $health->details);
    }

    #[Test]
    public function a_count_that_is_not_a_number_is_left_out(): void
    {
        $connection = new class
        {
            public function size(?string $queue = null): int
            {
                return 6;
            }

            public function pendingSize(?string $queue = null): ?int
            {
                return null;
            }

            public function delayedSize(?string $queue = null): string
            {
                return 'many';
            }

            public function reservedSize(?string $queue = null): int
            {
                return 2;
            }
        };

        $health = $this->indicator(['custom' => ['default']], ['custom' => $connection])->health();

        $this->assertSame(['queues' => ['custom' => ['default' => ['size' => 6, 'reserved' => 2]]]], $health->details);
    }

    #[Test]
    public function a_driver_that_forwards_calls_counts_only_what_it_declares(): void
    {
        $connection = new class
        {
            public function size(?string $queue = null): int
            {
                return 6;
            }

            /** @param  array<mixed>  $arguments */
            public function __call(string $method, array $arguments): never
            {
                throw new BadMethodCallException("Method [{$method}] does not exist.");
            }
        };

        $health = $this->indicator(['custom' => ['default']], ['custom' => $connection])->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(['queues' => ['custom' => ['default' => ['size' => 6]]]], $health->details);
    }

    #[Test]
    public function a_protected_count_is_not_reached_through_call(): void
    {
        $connection = new class
        {
            public function size(?string $queue = null): int
            {
                return 6;
            }

            protected function pendingSize(?string $queue = null): int
            {
                return 5;
            }

            /** @param  array<mixed>  $arguments */
            public function __call(string $method, array $arguments): never
            {
                throw new BadMethodCallException("Method [{$method}] does not exist.");
            }
        };

        $health = $this->indicator(['custom' => ['default']], ['custom' => $connection])->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(['queues' => ['custom' => ['default' => ['size' => 6]]]], $health->details);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badlyShapedQueues(): array
    {
        return [
            'no connection' => [['default', 'emails']],
            'no queue name' => [['redis' => []]],
            'a name that is not a string' => [['redis' => [1]]],
            'a value that is neither a name nor a list' => [['redis' => 1]],
            'a setting that is not a map' => ['default'],
        ];
    }

    #[Test]
    #[DataProvider('badlyShapedQueues')]
    public function badly_shaped_queues_are_rejected(mixed $queues): void
    {
        config()->set('actuator.queue.queues', $queues);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Watched queues must map a connection name to queue names: ['connection' => ['queue', ...]].");

        $this->indicator(null, [])->health();
    }

    #[Test]
    public function it_watches_the_configured_queues(): void
    {
        config()->set('actuator.queue.queues', ['redis' => ['emails']]);

        $health = $this->indicator(null, ['redis' => $this->sized(['emails' => 2])])->health();

        $this->assertSame(['queues' => ['redis' => ['emails' => ['size' => 2]]]], $health->details);
    }

    #[Test]
    public function without_queues_it_watches_the_default_queue_of_the_default_connection(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.queue', 'high');

        $health = $this->indicator(null, ['redis' => $this->sized(['high' => 7])])->health();

        $this->assertSame(['queues' => ['redis' => ['high' => ['size' => 7]]]], $health->details);
    }

    #[Test]
    public function a_connection_without_a_default_queue_watches_the_default_one(): void
    {
        config()->set('queue.default', 'custom');

        $health = $this->indicator([], ['custom' => $this->sized(['default' => 0])])->health();

        $this->assertSame(['queues' => ['custom' => ['default' => ['size' => 0]]]], $health->details);
    }

    #[Test]
    public function it_counts_the_jobs_of_the_database_driver(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Queue::connection('database')->pushRaw('{}', 'emails');
        Queue::connection('database')->pushRaw('{}', 'emails');

        $health = $this->app->make(QueueHealthIndicator::class, ['queues' => ['database' => ['emails']], 'warningThreshold' => 2])->health();

        $this->assertSame(Status::Warning, $health->status);
        $this->assertSame(2, $health->details['queues']['database']['emails']['size']);
    }

    #[Test]
    public function a_missing_jobs_table_is_down(): void
    {
        $health = $this->app->make(QueueHealthIndicator::class, ['queues' => ['database' => ['default']]])->health();

        $this->assertSame(Status::Down, $health->status);
        $this->assertStringStartsWith('Illuminate\Database\QueryException: ', $health->details['queues']['database']['default']['error']);
    }

    /**
     * @param  array<string, list<string>>|null  $queues
     * @param  array<string, object>  $connections  connection name => fake queue connection
     */
    private function indicator(?array $queues, array $connections, ?int $warningThreshold = null, ?int $downThreshold = null): QueueHealthIndicator
    {
        $factory = Mockery::mock(QueueFactory::class);
        foreach ($connections as $name => $connection) {
            $factory->allows('connection')->with($name)->andReturn($connection);
        }

        return new QueueHealthIndicator($factory, config(), $queues, $warningThreshold, $downThreshold);
    }

    /**
     * A connection of a driver that only counts the size of its queues.
     *
     * @param  array<string, int>  $sizes  queue name => size
     */
    private function sized(array $sizes): object
    {
        return new class($sizes)
        {
            /** @param  array<string, int>  $sizes */
            public function __construct(private array $sizes) {}

            public function size(?string $queue = null): int
            {
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
        return new class($counts)
        {
            /** @param  array<string, array{int, int, int, int}>  $counts */
            public function __construct(private array $counts) {}

            public function size(?string $queue = null): int
            {
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
