<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

/**
 * Measurements recorded by this process and not yet written to the store.
 *
 * @internal
 */
final class Buffer
{
    /**
     * Set while the registry talks to the store, so the store's own
     * queries and cache calls are not recorded as application work.
     */
    public bool $paused = false;

    /** @var array<string, float> */
    private array $increments = [];

    /** @var array<string, float> */
    private array $values = [];

    /**
     * A value that is not finite is ignored: it would turn a stored total
     * into NaN for good, and no store can add it.
     */
    public function increment(string $series, float $amount): void
    {
        if (! $this->paused && is_finite($amount)) {
            $this->increments[$series] = ($this->increments[$series] ?? 0.0) + $amount;
        }
    }

    public function set(string $series, float $value): void
    {
        if (! $this->paused && is_finite($value)) {
            $this->values[$series] = $value;
        }
    }

    /**
     * Records one observation of a timer or distribution summary. Only the
     * smallest bucket that holds the value is counted: the cumulative
     * counts are built when the meter is read.
     *
     * @param  list<float>  $buckets  upper bounds, ascending
     */
    public function observe(MeterId $meter, float $value, array $buckets): void
    {
        if (! is_finite($value)) {
            return;
        }

        $this->increment($meter->series('count'), 1.0);
        $this->increment($meter->series('sum'), $value);

        foreach ($buckets as $bound) {
            if ($value <= $bound) {
                $this->increment($meter->series("le:{$bound}"), 1.0);

                return;
            }
        }
    }

    /**
     * @return array{array<string, float>, array<string, float>} the increments and the values, removed from the buffer
     */
    public function drain(): array
    {
        $drained = [$this->increments, $this->values];
        $this->increments = [];
        $this->values = [];

        return $drained;
    }
}
