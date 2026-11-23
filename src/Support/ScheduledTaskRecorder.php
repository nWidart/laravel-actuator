<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Support;

use Exception;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Throwable;

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

    /**
     * The withoutOverlapping() filter of "schedule:run" skips a task whose mutex exists. Another
     * process can still take the mutex after that filter: the run is then skipped after this
     * event, and must not overwrite the last execution. The mutex of a run that goes ahead is
     * only created after this event, by Event::run().
     */
    public function starting(ScheduledTaskStarting $event): void
    {
        if (! self::overlaps($event->task)) {
            SchedulerStore::recordExecution($event->task, SchedulerStore::STARTED);
        }
    }

    /**
     * A background task is only launched here: ScheduledBackgroundTaskFinished reports its end.
     * A foreground command that exits non-zero is followed by ScheduledTaskFailed. A run skipped
     * because it overlaps another never happened.
     */
    public function finished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;

        if (! $task->runInBackground && ! $task->skippedBecauseOverlapping && in_array($task->exitCode, [0, null], true)) {
            SchedulerStore::recordExecution($task, SchedulerStore::SUCCESS);
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
     * The exception ScheduleRunCommand reports for a foreground command that exits non-zero, with
     * the task's short name instead of its command: the arguments can hold a secret.
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
            'message' => 'Scheduled command ['.ScheduledTaskTarget::name($task)."] failed with exit code [{$task->exitCode}].",
        ]);
    }

    /**
     * Whether another run of the task holds its mutex. A mutex that cannot be read counts as free:
     * recording must never fail the task.
     */
    private static function overlaps(Event $task): bool
    {
        try {
            return $task->withoutOverlapping && $task->mutex->exists($task);
        } catch (Throwable) {
            return false;
        }
    }
}
