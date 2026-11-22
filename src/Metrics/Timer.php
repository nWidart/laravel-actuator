<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

use Closure;

/**
 * Counts events and the time they took, in seconds.
 */
final readonly class Timer
{
    /**
     * @internal use MeterRegistry::timer()
     *
     * @param  list<float>  $buckets
     */
    public function __construct(private ?Buffer $buffer, private MeterId $id, private array $buckets) {}

    public function record(float $seconds): void
    {
        $this->buffer?->observe($this->id, max(0.0, $seconds), $this->buckets);
    }

    /**
     * Runs the callback and records how long it took, also when it throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function time(Closure $callback): mixed
    {
        $start = hrtime(true);

        try {
            return $callback();
        } finally {
            $this->record((hrtime(true) - $start) / 1e9);
        }
    }
}
