<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics\Binders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Nwidart\Actuator\Metrics\MeterBinder;
use Nwidart\Actuator\Metrics\MeterRegistry;

/**
 * Times every attempt to run a queued job. Tag contributors receive the
 * JobProcessed event, or the JobExceptionOccurred event when the job threw.
 *
 * @internal
 */
final class QueueJobMetrics implements MeterBinder
{
    public const NAME = 'queue.jobs';

    /** @var array<int, int|float> hrtime of the jobs that are running, by object id */
    private array $started = [];

    public function __construct(private readonly Dispatcher $events, private readonly Application $app) {}

    public function bind(MeterRegistry $registry): void
    {
        $registry->describe(self::NAME, 'Duration of queued job attempts.');

        if (! $registry->enabled(self::NAME)) {
            return;
        }

        $this->events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->started[spl_object_id($event->job)] = hrtime(true);
        });

        $this->events->listen(JobProcessed::class, function (JobProcessed $event) use ($registry): void {
            $this->record($registry, $event, match (true) {
                $event->job->hasFailed() => 'FAILED',
                $event->job->isReleased() => 'RELEASED',
                default => 'SUCCESS',
            });
        });

        // A worker decides that a job has failed for good before it raises this
        // event, and releases the job for another attempt after it. The sync
        // queue never retries, and marks the job as failed after the event.
        $this->events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use ($registry): void {
            $this->record($registry, $event, $event->job->hasFailed() || $event->job instanceof SyncJob ? 'FAILED' : 'RETRY');
        });
    }

    private function record(MeterRegistry $registry, JobProcessed|JobExceptionOccurred $event, string $outcome): void
    {
        $id = spl_object_id($event->job);
        $start = $this->started[$id] ?? null;
        unset($this->started[$id]);

        $registry->timer(self::NAME, [
            'connection' => $event->connectionName,
            'queue' => (string) $event->job->getQueue(),
            'job' => $event->job->resolveName(),
            'outcome' => $outcome,
            ...$registry->tagsFor(self::NAME, $event),
        ])->record($start === null ? 0.0 : (hrtime(true) - $start) / 1e9);

        // A queue worker lives for many jobs and may never terminate cleanly.
        if ($this->app->runningInConsole()) {
            $registry->flush();
        }
    }
}
