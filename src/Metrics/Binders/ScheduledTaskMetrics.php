<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics\Binders;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Nwidart\Actuator\Metrics\MeterBinder;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Actuator\Support\ScheduledTaskTarget;

/**
 * Times every run of a scheduled task, as Spring's "tasks.scheduled.execution"
 * does. Tag contributors receive the ScheduledTaskFinished event, or the
 * ScheduledTaskFailed event when the task threw.
 *
 * A task that runs in the background is timed until it has started.
 *
 * @internal
 */
final class ScheduledTaskMetrics implements MeterBinder
{
    public const NAME = 'tasks.scheduled.execution';

    /** @var array<int, int|float> hrtime of the tasks that are running, by object id */
    private array $started = [];

    public function __construct(private readonly Dispatcher $events) {}

    public function bind(MeterRegistry $registry): void
    {
        $registry->describe(self::NAME, 'Duration of scheduled task runs.');

        if (! $registry->enabled(self::NAME)) {
            return;
        }

        $this->events->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            $this->started[spl_object_id($event->task)] = hrtime(true);
        });

        $this->events->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) use ($registry): void {
            unset($this->started[spl_object_id($event->task)]);
            $this->record($registry, $event, $event->runtime, (int) $event->task->exitCode === 0 ? 'SUCCESS' : 'FAILED');
        });

        // A task that exits with a failure code first finishes and then fails: it is already recorded.
        $this->events->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use ($registry): void {
            $start = $this->started[spl_object_id($event->task)] ?? null;
            unset($this->started[spl_object_id($event->task)]);

            if ($start !== null) {
                $this->record($registry, $event, (hrtime(true) - $start) / 1e9, 'FAILED');
            }
        });
    }

    private function record(MeterRegistry $registry, ScheduledTaskFinished|ScheduledTaskFailed $event, float $seconds, string $outcome): void
    {
        $registry->timer(self::NAME, [
            'task' => ScheduledTaskTarget::of($event->task),
            'outcome' => $outcome,
            ...$registry->tagsFor(self::NAME, $event),
        ])->record($seconds);
    }
}
