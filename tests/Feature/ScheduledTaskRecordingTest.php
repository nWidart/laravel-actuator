<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Closure;
use Exception;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Nwidart\Actuator\Health\Indicators\SchedulerHealthIndicator;
use Nwidart\Actuator\Health\Status;
use Nwidart\Actuator\Support\SchedulerStore;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ScheduledTaskRecordingTest extends TestCase
{
    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-01 10:30:00 UTC');
        $this->schedule = $this->app->make(Schedule::class);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', ['scheduledtasks']);
        $app['config']->set('actuator.scheduledtasks.show_exceptions', 'always');
    }

    #[Test]
    public function a_task_that_succeeds_is_recorded_as_success(): void
    {
        $this->schedule->call(fn () => null)->everyMinute()->description('Prune');

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame(['time' => '2026-01-01T10:30:00+00:00', 'status' => 'SUCCESS'], $this->lastExecution('Prune'));
    }

    #[Test]
    public function a_running_task_is_recorded_as_started(): void
    {
        $this->schedule->call(fn () => $this->assertSame('STARTED', $this->lastExecution('Prune')['status'] ?? null))
            ->everyMinute()->description('Prune');

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame('SUCCESS', $this->lastExecution('Prune')['status'] ?? null);
    }

    #[Test]
    public function a_task_that_throws_is_recorded_as_error_with_its_exception(): void
    {
        $this->schedule->call(fn () => throw new RuntimeException('Mail server down'))->everyMinute()->description('Send');

        $this->artisan('schedule:run');

        $this->assertSame([
            'time' => '2026-01-01T10:30:00+00:00',
            'status' => 'ERROR',
            'exception' => ['type' => RuntimeException::class, 'message' => 'Mail server down'],
        ], $this->lastExecution('Send'));
    }

    #[Test]
    public function a_command_that_exits_non_zero_is_an_error_after_it_finished(): void
    {
        $this->schedule->exec('sh -c "exit 3" --token=secret')->everyMinute();
        $order = [];
        Event::listen(ScheduledTaskFinished::class, function () use (&$order): void {
            $order[] = 'finished';
        });
        Event::listen(ScheduledTaskFailed::class, function () use (&$order): void {
            $order[] = 'failed';
        });

        $this->artisan('schedule:run');

        $this->assertSame(['finished', 'failed'], $order);
        $this->assertSame([
            'time' => '2026-01-01T10:30:00+00:00',
            'status' => 'ERROR',
            'exception' => ['type' => Exception::class, 'message' => 'Scheduled command [sh] failed with exit code [3].'],
        ], $this->lastExecution('sh -c "exit 3" --token=secret'));
    }

    #[Test]
    public function a_callback_that_throws_a_plain_exception_keeps_its_message(): void
    {
        $this->schedule->call(fn () => throw new Exception('Quota reached'))->everyMinute()->description('Send');

        $this->artisan('schedule:run');

        $this->assertSame(['type' => Exception::class, 'message' => 'Quota reached'], $this->lastExecution('Send')['exception'] ?? null);
    }

    #[Test]
    public function a_command_that_exits_zero_is_a_success(): void
    {
        $this->schedule->exec('exit 0')->everyMinute();

        $this->artisan('schedule:run');

        $this->assertSame('SUCCESS', $this->lastExecution('exit 0')['status'] ?? null);
    }

    #[Test]
    public function a_long_message_is_truncated(): void
    {
        $this->schedule->call(fn () => throw new RuntimeException(str_repeat('a', 5000)))->everyMinute()->description('Send');

        $this->artisan('schedule:run');

        $this->assertSame(str_repeat('a', 1000).'...', $this->lastExecution('Send')['exception']['message'] ?? null);
    }

    #[Test]
    public function a_background_task_stays_started_until_it_ends(): void
    {
        $task = $this->schedule->command('report:send')->everyMinute()->runInBackground();

        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFinished($task, 0.01));
        $this->assertSame('STARTED', $this->lastExecution('php artisan report:send')['status'] ?? null);

        Carbon::setTestNow('2026-01-01 10:32:00 UTC');
        $this->artisan('schedule:finish', ['id' => $task->mutexName(), 'code' => 0])->assertExitCode(0);
        $this->assertSame(['time' => '2026-01-01T10:32:00+00:00', 'status' => 'SUCCESS'], $this->lastExecution('php artisan report:send'));

        $this->artisan('schedule:finish', ['id' => $task->mutexName(), 'code' => 2])->assertExitCode(0);
        $this->assertSame([
            'time' => '2026-01-01T10:32:00+00:00',
            'status' => 'ERROR',
            'exception' => ['type' => Exception::class, 'message' => 'Scheduled command [report:send] failed with exit code [2].'],
        ], $this->lastExecution('php artisan report:send'));
    }

    #[Test]
    public function a_run_skipped_for_overlap_after_it_started_stays_started(): void
    {
        if (! property_exists(SchedulingEvent::class, 'skippedBecauseOverlapping')) {
            $this->markTestSkipped('Laravel 12 does not mark a run skipped after ScheduledTaskStarting: it is recorded as SUCCESS.');
        }

        $ran = false;
        $task = $this->schedule->call(function () use (&$ran): void {
            $ran = true;
        })->everyMinute()->description('Prune')->withoutOverlapping();
        $task->mutex = $this->mutex(takenAfterTheFilters: true);

        $this->artisan('schedule:run');

        $this->assertFalse($ran);
        $this->assertSame(['time' => '2026-01-01T10:30:00+00:00', 'status' => 'STARTED'], $this->lastExecution('Prune'));
    }

    #[Test]
    public function recording_never_touches_the_task_mutex(): void
    {
        $task = $this->schedule->exec('exit 0')->everyMinute()->withoutOverlapping();
        $task->mutex = $mutex = $this->mutex(takenAfterTheFilters: false);

        $this->artisan('schedule:run');

        // Only Laravel's own calls: the withoutOverlapping() filter, then the run.
        $this->assertSame(['exists' => 1, 'create' => 1], $mutex->calls);
        $this->assertSame('SUCCESS', $this->lastExecution('exit 0')['status'] ?? null);
    }

    #[Test]
    public function with_recording_off_the_listeners_touch_neither_the_mutex_nor_the_store(): void
    {
        config()->set('actuator.scheduler.record', false);
        $this->useStore(new class extends ArrayStore
        {
            public function get($key): mixed
            {
                throw new RuntimeException('The store was used.');
            }

            public function put($key, $value, $seconds): bool
            {
                throw new RuntimeException('The store was used.');
            }

            public function forever($key, $value): bool
            {
                throw new RuntimeException('The store was used.');
            }
        });
        $task = $this->schedule->call(fn () => throw new RuntimeException('Mail server down'))->everyMinute()->description('Send')->withoutOverlapping();
        $task->mutex = $mutex = $this->mutex(takenAfterTheFilters: false);

        $this->startCommand('schedule:run');
        $this->artisan('schedule:run');

        $this->assertSame(['exists' => 1, 'create' => 1], $mutex->calls);
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function a_skipped_task_keeps_its_last_execution(): void
    {
        $due = true;
        $this->schedule->call(fn () => null)->everyMinute()->description('Prune')->when(function () use (&$due): bool {
            return $due;
        });
        $this->artisan('schedule:run');

        $due = false;
        Carbon::setTestNow('2026-01-01 10:31:00 UTC');
        $this->artisan('schedule:run');

        $this->assertSame(['time' => '2026-01-01T10:30:00+00:00', 'status' => 'SUCCESS'], $this->lastExecution('Prune'));
    }

    #[Test]
    public function each_schedule_run_is_a_tick(): void
    {
        $this->assertSame(Status::Down, (new SchedulerHealthIndicator)->health()->status);

        $this->startCommand('schedule:run');

        $health = (new SchedulerHealthIndicator)->health();
        $this->assertSame(Status::Up, $health->status);
        $this->assertSame('2026-01-01T10:30:00+00:00', $health->details['lastTick']);
    }

    #[Test]
    public function a_tick_reads_back_from_a_store_that_returns_numbers_as_strings(): void
    {
        // Like RedisStore, which stores numbers unserialized and reads them back as strings.
        $this->useStore(new class extends ArrayStore
        {
            public function get($key): mixed
            {
                $value = parent::get($key);

                return is_int($value) ? (string) $value : $value;
            }
        });
        $this->schedule->call(fn () => null)->everyMinute()->description('Prune');

        $this->startCommand('schedule:run');
        $this->artisan('schedule:run');

        $health = (new SchedulerHealthIndicator)->health();
        $this->assertSame(Status::Up, $health->status);
        $this->assertSame('2026-01-01T10:30:00+00:00', $health->details['lastTick']);
        $this->assertSame(['time' => '2026-01-01T10:30:00+00:00', 'status' => 'SUCCESS'], $this->lastExecution('Prune'));
    }

    #[Test]
    public function another_command_is_not_a_tick(): void
    {
        $this->startCommand('schedule:list');

        $this->assertNull((new SchedulerHealthIndicator)->health()->details['lastTick']);
    }

    #[Test]
    public function the_store_is_configurable(): void
    {
        config()->set('actuator.scheduler.store', 'file');
        Cache::store('file')->flush();
        $this->schedule->call(fn () => null)->everyMinute()->description('Prune');

        try {
            $this->artisan('schedule:run');
            config()->set('actuator.scheduler.store', 'array');
            $this->assertSame([], $this->lastExecution('Prune'));
            config()->set('actuator.scheduler.store', 'file');
            $this->assertSame('SUCCESS', $this->lastExecution('Prune')['status'] ?? null);
        } finally {
            Cache::store('file')->flush();
        }
    }

    #[Test]
    public function the_cache_key_never_holds_the_target(): void
    {
        $store = new class extends ArrayStore
        {
            /** @var list<string> */
            public array $keys = [];

            public function put($key, $value, $seconds): bool
            {
                $this->keys[] = $key;

                return parent::put($key, $value, $seconds);
            }
        };
        $this->useStore($store);
        $task = $this->schedule->command('report:send --token=secret')->everyMinute();

        event(new ScheduledTaskStarting($task));

        $this->assertCount(1, $store->keys);
        $this->assertStringStartsWith('actuator:scheduler:task:', $store->keys[0]);
        $this->assertStringNotContainsString('secret', $store->keys[0]);
        $this->assertStringNotContainsString('report:send', $store->keys[0]);
        $this->assertSame('STARTED', $this->lastExecution('php artisan report:send --token=secret')['status'] ?? null);
    }

    #[Test]
    public function the_store_comes_from_the_current_container(): void
    {
        $task = $this->schedule->call(fn () => null)->everyMinute()->description('Prune');
        // Under Octane each request runs in a clone of the application that
        // Container::getInstance() points to, with its own cache manager.
        $sandbox = clone $this->app;
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        $sandbox->instance('cache', new CacheManager($sandbox));
        Container::setInstance($sandbox);

        try {
            event(new ScheduledTaskStarting($task));
            $inSandbox = SchedulerStore::lastExecutions(['Prune']);
        } finally {
            Container::setInstance($this->app);
        }

        $this->assertSame('STARTED', $inSandbox['Prune']['status'] ?? null);
        $this->assertSame([], SchedulerStore::lastExecutions(['Prune']));
    }

    #[Test]
    public function nothing_is_recorded_when_recording_is_off(): void
    {
        config()->set('actuator.scheduler.record', false);
        $this->schedule->call(fn () => null)->everyMinute()->description('Prune');

        $this->artisan('schedule:run');
        $this->startCommand('schedule:run');
        config()->set('actuator.scheduler.record', true);

        $this->assertSame([], $this->lastExecution('Prune'));
        $this->assertNull((new SchedulerHealthIndicator)->health()->details['lastTick']);
    }

    #[Test]
    public function a_store_that_throws_never_breaks_a_task_or_the_endpoint(): void
    {
        $this->useStore(new class extends ArrayStore
        {
            public function put($key, $value, $seconds): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function forever($key, $value): bool
            {
                throw new RuntimeException('Connection refused');
            }

            public function many(array $keys): array
            {
                throw new RuntimeException('Connection refused');
            }
        });
        $ran = 0;
        $this->schedule->call(function () use (&$ran): void {
            $ran++;
        })->everyMinute()->description('Prune');

        $this->artisan('schedule:run')->assertExitCode(0);
        $this->artisan('schedule:run')->assertExitCode(0);

        $this->startCommand('schedule:run');

        $this->assertSame(2, $ran);
        $this->getJson('/actuator/scheduledtasks')->assertOk()->assertJsonMissingPath('cron.0.lastExecution');
        $this->getJson('/actuator/scheduledtasks')->assertOk();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'Scheduler record could not be written: Connection refused. Recording stops for this process.')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'Scheduled task executions could not be read: Connection refused')->once();
    }

    /**
     * @return iterable<string, array{Closure(): bool, int}>
     */
    public static function failedWrites(): iterable
    {
        // A tick, then STARTED and SUCCESS twice: five writes.
        yield 'a put that throws stops the writes' => [fn (): bool => throw new RuntimeException('Connection timed out'), 1];
        // MySQL's upsert reports 0 rows for an identical row, so DatabaseStore returns false.
        yield 'a put that returns false keeps writing' => [fn (): bool => false, 5];
    }

    #[Test]
    #[DataProvider('failedWrites')]
    public function only_a_write_that_throws_stops_the_writes_for_the_process(Closure $put, int $puts): void
    {
        $store = new class($put) extends ArrayStore
        {
            public int $puts = 0;

            public function __construct(private readonly Closure $put)
            {
                parent::__construct();
            }

            public function put($key, $value, $seconds): bool
            {
                $this->puts++;

                return ($this->put)();
            }

            public function forever($key, $value): bool
            {
                return $this->put($key, $value, 0);
            }
        };
        $this->useStore($store);
        $this->schedule->call(fn () => null)->everyMinute()->description('Prune');

        $this->startCommand('schedule:run');
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame($puts, $store->puts);
    }

    #[Test]
    public function a_store_that_rejects_a_write_never_breaks_a_task(): void
    {
        $this->useStore(new class extends ArrayStore
        {
            public function put($key, $value, $seconds): bool
            {
                return false;
            }

            public function forever($key, $value): bool
            {
                return false;
            }
        });
        $ran = false;
        $this->schedule->call(function () use (&$ran): void {
            $ran = true;
        })->everyMinute()->description('Prune');

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertTrue($ran);
        $this->getJson('/actuator/scheduledtasks')->assertOk()->assertJsonMissingPath('cron.0.lastExecution');
        Log::shouldHaveReceived('warning')->with('Scheduler record could not be written: the store rejected the entry.', [])->once();
    }

    /**
     * A task mutex that counts Laravel's calls. It is free for the withoutOverlapping() filter of
     * "schedule:run". With $takenAfterTheFilters, another process takes it before the run, which
     * is then skipped after ScheduledTaskStarting.
     *
     * @return EventMutex&object{calls: array{exists: int, create: int}}
     */
    private function mutex(bool $takenAfterTheFilters): EventMutex
    {
        return new class($takenAfterTheFilters) implements EventMutex
        {
            /** @var array{exists: int, create: int} */
            public array $calls = ['exists' => 0, 'create' => 0];

            public function __construct(private readonly bool $taken) {}

            public function create(SchedulingEvent $event): bool
            {
                $this->calls['create']++;

                return ! $this->taken;
            }

            public function exists(SchedulingEvent $event): bool
            {
                $this->calls['exists']++;

                return false;
            }

            public function forget(SchedulingEvent $event): void {}
        };
    }

    /**
     * The console kernel dispatches CommandStarting outside of unit tests only.
     */
    private function startCommand(string $command): void
    {
        event(new CommandStarting($command, new ArrayInput([]), new NullOutput));
    }

    private function useStore(ArrayStore $store): void
    {
        config()->set('cache.stores.broken', ['driver' => 'broken']);
        config()->set('actuator.scheduler.store', 'broken');
        Cache::extend('broken', fn () => Cache::repository($store));
        Log::spy();
    }

    /**
     * The task's lastExecution in the scheduledtasks endpoint, or [] when it has none.
     *
     * @return array<string, mixed>
     */
    private function lastExecution(string $target): array
    {
        $response = $this->getJson('/actuator/scheduledtasks')->assertOk();

        foreach ([...$response->json('cron'), ...$response->json('fixedRate')] as $task) {
            if ($task['runnable']['target'] === $target) {
                return $task['lastExecution'] ?? [];
            }
        }

        $this->fail("No task [{$target}].");
    }
}
