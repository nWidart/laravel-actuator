<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;
use Nwidart\Actuator\Support\SchedulerStore;

/**
 * Checks that the scheduler ticks: DOWN when "schedule:run" has not started for $downAfter
 * minutes, or never did; WARNING after $warningAfter minutes (null turns WARNING off).
 * Reads the store set in "actuator.scheduler.store", which the scheduler writes to.
 */
final readonly class SchedulerHealthIndicator implements HealthIndicator
{
    public function __construct(private int $downAfter = 5, private ?int $warningAfter = 2)
    {
        if ($downAfter < 1 || ($warningAfter !== null && $warningAfter < 1)) {
            throw new InvalidArgumentException('Scheduler health thresholds must be 1 minute or more.');
        }
    }

    public function health(): Health
    {
        $details = ['downAfter' => $this->downAfter, 'warningAfter' => $this->warningAfter];

        if (! SchedulerStore::recording()) {
            return Health::unknown([...$details, 'error' => 'Scheduler recording is off: set [actuator.scheduler.record] to true.']);
        }

        $lastTick = SchedulerStore::lastTick();
        $details = ['lastTick' => $lastTick === null ? null : $lastTick->toIso8601String(), ...$details];

        if ($lastTick === null || $lastTick->lt(Date::now()->subMinutes($this->downAfter))) {
            return Health::down($details);
        }

        if ($this->warningAfter !== null && $lastTick->lt(Date::now()->subMinutes($this->warningAfter))) {
            return Health::warning($details);
        }

        return Health::up($details);
    }
}
