<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Support;

use Exception;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

/**
 * Records the last execution of each scheduled task, and each tick of "schedule:run", in the
 * SchedulerStore. A skipped task keeps its last execution. Each listener returns at once when
 * recording is off, and none touches the task's mutex: recording never changes whether a task runs.
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
        if ($event->command === 'schedule:run' && SchedulerStore::recording()) {
            SchedulerStore::recordTick();
        }
    }

    /**
     * The withoutOverlapping() filter of "schedule:run" skips a task whose mutex exists. Another
     * process can still take the mutex after that filter: Event::run() then skips the run after
     * this event, which has recorded STARTED. The mutex is not checked here: on a store with locks,
     * EventMutex::exists() briefly takes the lock, and could make another process skip a real run.
     */
    public function starting(ScheduledTaskStarting $event): void
    {
        if (SchedulerStore::recording()) {
            SchedulerStore::recordExecution($event->task, SchedulerStore::STARTED);
        }
    }

    /**
     * A background task is only launched here: ScheduledBackgroundTaskFinished reports its end.
     * A foreground command that exits non-zero is followed by ScheduledTaskFailed. A run skipped
     * because it overlaps another never happened: it stays STARTED. Laravel 12 has no
     * skippedBecauseOverlapping: such a run is recorded as SUCCESS there.
     */
    public function finished(ScheduledTaskFinished $event): void
    {
        if (! SchedulerStore::recording()) {
            return;
        }

        $task = $event->task;
        // Read by name, so static analysis passes on Laravel 12 and 13 alike.
        $skipped = (get_object_vars($task)['skippedBecauseOverlapping'] ?? false) === true;

        if (! $task->runInBackground && ! $skipped && in_array($task->exitCode, [0, null], true)) {
            SchedulerStore::recordExecution($task, SchedulerStore::SUCCESS);
        }
    }

    /**
     * For a command that exits non-zero, ScheduleRunCommand throws an Exception whose message holds
     * the whole command: it is replaced by the same message as for a background command. Other
     * exceptions keep their message.
     */
    public function failed(ScheduledTaskFailed $event): void
    {
        if (! SchedulerStore::recording()) {
            return;
        }

        $task = $event->task;
        $exception = $event->exception;

        if (! $task instanceof CallbackEvent && $exception::class === Exception::class && ! in_array($task->exitCode, [0, null], true)) {
            SchedulerStore::recordExecution($task, SchedulerStore::ERROR, self::exitCodeException($task));

            return;
        }

        SchedulerStore::recordExecution($task, SchedulerStore::ERROR, [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        if (! SchedulerStore::recording()) {
            return;
        }

        $task = $event->task;

        if ($task->exitCode === 0) {
            SchedulerStore::recordExecution($task, SchedulerStore::SUCCESS);

            return;
        }

        SchedulerStore::recordExecution($task, SchedulerStore::ERROR, self::exitCodeException($task));
    }

    /**
     * The exception ScheduleRunCommand reports for a command that exits non-zero, with the task's
     * short name instead of its command: the arguments can hold a secret.
     *
     * @return array{type: string, message: string}
     */
    private static function exitCodeException(Event $task): array
    {
        return [
            'type' => Exception::class,
            'message' => 'Scheduled command ['.ScheduledTaskTarget::name($task)."] failed with exit code [{$task->exitCode}].",
        ];
    }
}
