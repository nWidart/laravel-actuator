<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Support;

use Exception;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;

/**
 * Records the last execution of each scheduled task, and each tick of "schedule:run", in the
 * SchedulerStore. A skipped task keeps its last execution.
 *
 * @internal
 */
final class ScheduledTaskRecorder
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            CommandStarting::class => 'tick',
            ScheduledTaskStarting::class => 'starting',
            ScheduledTaskFinished::class => 'finished',
            ScheduledTaskFailed::class => 'failed',
            ScheduledBackgroundTaskFinished::class => 'backgroundFinished',
        ];
    }

    /**
     * "schedule:work" starts "schedule:run" every minute, so each run is a tick.
     */
    public function tick(CommandStarting $event): void
    {
        if ($event->command === 'schedule:run') {
            SchedulerStore::recordTick();
        }
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        SchedulerStore::recordExecution($event->task, SchedulerStore::STARTED);
    }

    /**
     * A background task is only launched here: ScheduledBackgroundTaskFinished reports its end.
     * A foreground command that exits non-zero is followed by ScheduledTaskFailed.
     */
    public function finished(ScheduledTaskFinished $event): void
    {
        if (! $event->task->runInBackground && in_array($event->task->exitCode, [0, null], true)) {
            SchedulerStore::recordExecution($event->task, SchedulerStore::SUCCESS);
        }
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        SchedulerStore::recordExecution($event->task, SchedulerStore::ERROR, [
            'type' => $event->exception::class,
            'message' => $event->exception->getMessage(),
        ]);
    }

    /**
     * The same exception as ScheduleRunCommand reports for a foreground command that exits non-zero.
     */
    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $task = $event->task;

        if ($task->exitCode === 0) {
            SchedulerStore::recordExecution($task, SchedulerStore::SUCCESS);

            return;
        }

        SchedulerStore::recordExecution($task, SchedulerStore::ERROR, [
            'type' => Exception::class,
            'message' => "Scheduled command [{$task->command}] failed with exit code [{$task->exitCode}].",
        ]);
    }
}
