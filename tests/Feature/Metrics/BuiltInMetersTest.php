<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Metrics;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Actuator\Metrics\MeterSnapshot;
use Nwidart\Actuator\Metrics\MeterType;
use Nwidart\Actuator\Tests\Fixtures\FailingJob;
use Nwidart\Actuator\Tests\Fixtures\SucceedingJob;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

final class BuiltInMetersTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', ['prometheus']);
    }

    /**
     * @return list<MeterSnapshot>
     */
    private function meters(string $name): array
    {
        $registry = $this->app->make(MeterRegistry::class);
        $registry->flush();

        return array_values(array_filter($registry->collect(), static fn (MeterSnapshot $meter): bool => $meter->name === $name));
    }

    #[Test]
    public function queued_jobs_are_timed_per_attempt(): void
    {
        $this->app->make(MeterRegistry::class)->tagUsing(
            'queue.jobs',
            static fn (JobProcessed|JobExceptionOccurred $event): array => ['module' => class_basename($event->job->resolveName())],
        );

        dispatch(new SucceedingJob);
        dispatch(new SucceedingJob);

        try {
            dispatch(new FailingJob);
        } catch (RuntimeException) {
            // The sync queue rethrows.
        }

        [$failed, $succeeded] = $this->meters('queue.jobs');

        $this->assertSame(MeterType::Timer, $succeeded->type);
        $this->assertSame(2.0, $succeeded->count);
        $this->assertSame(
            ['connection' => 'sync', 'job' => SucceedingJob::class, 'module' => 'SucceedingJob', 'outcome' => 'SUCCESS', 'queue' => 'sync'],
            $succeeded->tags,
        );
        $this->assertSame(1.0, $failed->count);
        $this->assertSame(FailingJob::class, $failed->tags['job']);
        $this->assertSame('FAILED', $failed->tags['outcome']);
        $this->assertNotEmpty($succeeded->buckets);
    }

    #[Test]
    public function an_attempt_that_will_be_retried_is_not_a_failure(): void
    {
        $events = $this->app->make('events');

        foreach ([false, false, true] as $failedForGood) {
            $job = Mockery::mock(Job::class);
            $job->allows('hasFailed')->andReturn($failedForGood);
            $job->allows('payload')->andReturn([]);
            $job->allows('getQueue')->andReturn('default');
            $job->allows('resolveName')->andReturn('App\\Jobs\\SendInvoice');

            $events->dispatch(new JobProcessing('redis', $job));
            $events->dispatch(new JobExceptionOccurred('redis', $job, new RuntimeException('timeout')));
        }

        $outcomes = [];

        foreach ($this->meters('queue.jobs') as $meter) {
            $outcomes[$meter->tags['outcome']] = $meter->count;
        }

        $this->assertSame(['FAILED' => 1.0, 'RETRY' => 2.0], $outcomes);
    }

    #[Test]
    public function database_queries_are_timed_per_connection(): void
    {
        $this->app->make(MeterRegistry::class)->tagUsing('db.queries', static fn (QueryExecuted $event): array => ['module' => 'Blog']);

        DB::select('select 1');
        DB::select('select 2');

        [$queries] = $this->meters('db.queries');

        $this->assertSame(2.0, $queries->count);
        $this->assertSame(['connection' => 'testing', 'module' => 'Blog'], $queries->tags);
        $this->assertGreaterThanOrEqual(0.0, $queries->sum);
    }

    #[Test]
    public function reported_exceptions_are_counted_by_class(): void
    {
        $this->app->make(MeterRegistry::class)->tagUsing('exceptions', static fn (Throwable $e): array => ['module' => $e->getMessage()]);

        report(new RuntimeException('Blog'));
        report(new RuntimeException('Blog'));
        report(new LogicException('Shop'));

        [$logic, $runtime] = $this->meters('exceptions');

        $this->assertSame(MeterType::Counter, $runtime->type);
        $this->assertSame(2.0, $runtime->value);
        $this->assertSame(['exception' => 'RuntimeException', 'module' => 'Blog'], $runtime->tags);
        $this->assertSame(['exception' => 'LogicException', 'module' => 'Shop'], $logic->tags);
    }

    #[Test]
    public function scheduled_tasks_are_timed_by_outcome(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $events = $this->app->make('events');

        // The arguments of a command can hold a secret: they are not part of the name.
        $report = $schedule->command('report:send --token=secret');
        $report->exitCode = 0;
        $events->dispatch(new ScheduledTaskStarting($report));
        $events->dispatch(new ScheduledTaskFinished($report, 1.5));

        // A command that exits with a failure code finishes first and fails afterwards.
        $import = $schedule->command('import:run');
        $import->exitCode = 1;
        $events->dispatch(new ScheduledTaskStarting($import));
        $events->dispatch(new ScheduledTaskFinished($import, 0.5));
        $events->dispatch(new ScheduledTaskFailed($import, new RuntimeException('exit code 1')));

        // A callback that throws only fails.
        $cleanup = $schedule->call(fn () => null)->description('cleanup');
        $events->dispatch(new ScheduledTaskStarting($cleanup));
        $events->dispatch(new ScheduledTaskFailed($cleanup, new RuntimeException('thrown')));

        $tasks = [];

        foreach ($this->meters('tasks.scheduled.execution') as $meter) {
            $tasks[$meter->tags['task']] = [$meter->tags['outcome'], $meter->count];
        }

        $this->assertSame(
            ['cleanup' => ['FAILED', 1.0], 'import:run' => ['FAILED', 1.0], 'report:send' => ['SUCCESS', 1.0]],
            $tasks,
        );
        $this->assertSame(1.5, $this->meters('tasks.scheduled.execution')[2]->sum);
    }

    #[Test]
    public function a_task_that_did_not_run_is_not_a_success(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $events = $this->app->make('events');

        // Laravel raises "finished" for a task it skipped because the previous run still holds the lock.
        $skipped = $schedule->command('report:send')->withoutOverlapping();
        $events->dispatch(new ScheduledTaskStarting($skipped));
        $events->dispatch(new ScheduledTaskFinished($skipped, 0.0));

        // A background task has no exit code yet either, but it was started.
        $background = $schedule->command('import:run')->runInBackground();
        $events->dispatch(new ScheduledTaskStarting($background));
        $events->dispatch(new ScheduledTaskFinished($background, 0.0));

        $tasks = array_map(static fn (MeterSnapshot $meter): string => $meter->tags['task'], $this->meters('tasks.scheduled.execution'));

        $this->assertSame(['import:run'], $tasks);
    }

    #[Test]
    public function a_job_that_timed_out_is_counted_once(): void
    {
        $events = $this->app->make('events');
        $job = Mockery::mock(Job::class);
        $job->allows('payload')->andReturn([]);
        $job->allows('hasFailed')->andReturn(true);
        $job->allows('getQueue')->andReturn('default');
        $job->allows('resolveName')->andReturn('App\\Jobs\\SendInvoice');

        // The worker kills itself after the timeout. When it is told not to, the exception follows.
        $events->dispatch(new JobProcessing('redis', $job));
        $events->dispatch(new JobTimedOut('redis', $job));
        $events->dispatch(new JobExceptionOccurred('redis', $job, new RuntimeException('timed out')));

        [$attempt] = $this->meters('queue.jobs');

        $this->assertSame('FAILED', $attempt->tags['outcome']);
        $this->assertSame(1.0, $attempt->count);
    }

    #[Test]
    public function the_runtime_is_described_by_gauges(): void
    {
        [$php] = $this->meters('php.info');
        [$load] = $this->meters('system.load.average.1m');
        [$total] = $this->meters('disk.total');

        $this->assertSame(['sapi' => PHP_SAPI, 'version' => PHP_VERSION], $php->tags);
        $this->assertSame(1.0, $php->value);
        $this->assertGreaterThanOrEqual(0.0, $load->value);
        $this->assertSame('bytes', $total->unit);
        $this->assertGreaterThan(0.0, $total->value);
    }
}
