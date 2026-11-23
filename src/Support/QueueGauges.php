<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Support;

use Illuminate\Container\Container as CurrentContainer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use InvalidArgumentException;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Metrics\Metrics;
use Throwable;

/**
 * Exports the size of the watched queues and the number of failed jobs as callback gauges, read
 * during the scrape. "laravel.queue.size" has one series per queue, "laravel.queue.jobs" one per
 * queue and state where the driver counts them, and "laravel.queue.failed.jobs" one for all failed
 * jobs, where the failer counts them.
 *
 * Off by default ("queue.metrics"). Each queue is read once per scrape. A queue that cannot be
 * read loses only its own series. A connection that fails on its first queue is not tried again for
 * its other queues in the same scrape: each would wait for the connect timeout.
 *
 * @internal
 */
final class QueueGauges
{
    public const SIZE_METER = 'laravel.queue.size';

    public const JOBS_METER = 'laravel.queue.jobs';

    public const FAILED_METER = 'laravel.queue.failed.jobs';

    private const STATES = ['pending', 'delayed', 'reserved'];

    /** @var array<string, array<string, array<string, int|Throwable>|Throwable>>|null connection => queue => size and states, or why the queue could not be read */
    private ?array $counts = null;

    /**
     * @param  array<string, list<string>>  $queues  connection => queue names
     */
    private function __construct(private readonly array $queues) {}

    /**
     * Registers the gauges when "queue.metrics" is on. Nothing runs until the metrics are exported.
     */
    public static function register(Container $container, MeterRegistry $registry, Metrics $metrics): void
    {
        /** @var Repository $config */
        $config = $container->make('config');

        // Off unless turned on, also with a published configuration that predates the key: each
        // scrape would count the jobs of every watched queue.
        if (! filter_var($config->get('actuator.queue.metrics', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        if (! $registry->enabled(self::SIZE_METER) && ! $registry->enabled(self::JOBS_METER) && ! $registry->enabled(self::FAILED_METER)) {
            return;
        }

        try {
            $queues = WatchedQueues::resolve($config);
        } catch (InvalidArgumentException $exception) {
            // A badly shaped "queue.queues" must not break every request: the queue gauges are left
            // out, and the error is reported when the metrics are exported, not on every boot.
            $metrics->onExport(static function () use ($exception): void {
                report($exception);
            });
            $queues = [];
        }

        $registry->describe(self::FAILED_METER, 'Failed jobs, as counted by the failer.');
        $registry->gauge(self::FAILED_METER, [], static function (): ?int {
            // Any failer: Larastan reads the binding as the default one, which counts.
            /** @var FailedJobProviderInterface $failer */
            $failer = CurrentContainer::getInstance()->make(FailedJobProviderInterface::class);

            return $failer instanceof CountableFailedJobProvider ? (int) $failer->count() : null;
        });

        if ($queues === []) {
            return;
        }

        $gauges = new self($queues);

        // Each export reads the queues again. Export callbacks all run before any gauge is read.
        $metrics->onExport(static function () use ($gauges): void {
            $gauges->counts = null;
        });

        $registry->describe(self::SIZE_METER, 'Jobs in each watched queue, as the driver counts its size.');
        $registry->describe(self::JOBS_METER, 'Jobs in each watched queue by state, for the drivers that count them.');

        foreach ($queues as $connection => $names) {
            foreach ($names as $name) {
                $tags = ['connection' => $connection, 'queue' => $name];
                $registry->gauge(self::SIZE_METER, $tags, static fn (): ?int => $gauges->count($connection, $name, 'size'));

                foreach (self::STATES as $state) {
                    $registry->gauge(self::JOBS_METER, [...$tags, 'state' => $state], static fn (): ?int => $gauges->count($connection, $name, $state));
                }
            }
        }
    }

    /**
     * What the queue holds in $key this export, or null when its driver does not count it. A queue
     * or count that cannot be read throws for each of its series: the registry reports it once and
     * skips them.
     */
    private function count(string $connection, string $name, string $key): ?int
    {
        $counts = $this->counts()[$connection][$name] ?? null;
        $count = $counts instanceof Throwable ? $counts : $counts[$key] ?? null;

        if ($count instanceof Throwable) {
            throw $count;
        }

        return $count;
    }

    /**
     * @return array<string, array<string, array<string, int|Throwable>|Throwable>>
     */
    private function counts(): array
    {
        if ($this->counts === null) {
            $this->counts = [];

            foreach ($this->queues as $connection => $names) {
                // Resolved from the current container, not the one of the registration: under Octane
                // that is the request's sandbox, while the gauges live as long as the worker.
                $this->counts[$connection] = WatchedQueues::read(
                    static fn () => CurrentContainer::getInstance()->make(QueueFactory::class)->connection($connection),
                    $names,
                );
            }
        }

        return $this->counts;
    }
}
