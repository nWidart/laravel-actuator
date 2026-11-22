<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

/**
 * Where measurements live between requests. PHP processes share no memory,
 * so every web worker, queue worker and scheduler run writes to the store,
 * and the process that answers a scrape reads it.
 *
 * Series keys are opaque strings. A store never needs to parse them.
 */
interface MetricsStore
{
    /**
     * Adds each increment to its series, and overwrites each series in $values.
     * An increment must not be lost when two processes write at the same time.
     *
     * @param  array<string, float>  $increments  series key => amount to add
     * @param  array<string, float>  $values  series key => value to set
     */
    public function write(array $increments, array $values): void;

    /**
     * @return array<string, float> every series, by key
     */
    public function read(): array;

    /**
     * Removes every series.
     */
    public function clear(): void;
}
