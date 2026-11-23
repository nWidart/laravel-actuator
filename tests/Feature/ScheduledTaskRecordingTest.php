<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Exception;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Nwidart\Actuator\Health\Indicators\SchedulerHealthIndicator;
use Nwidart\Actuator\Health\Status;
use Nwidart\Actuator\Tests\TestCase;
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
        $this->schedule->exec('exit 3')->everyMinute();
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
            'exception' => ['type' => Exception::class, 'message' => 'Scheduled command [exit 3] failed with exit code [3].'],
        ], $this->lastExecution('exit 3'));
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
            'exception' => ['type' => Exception::class, 'message' => "Scheduled command [{$task->command}] failed with exit code [2]."],
        ], $this->lastExecution('php artisan report:send'));
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
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'Scheduler record could not be written: Connection refused')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'Scheduled task executions could not be read: Connection refused')->once();
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
