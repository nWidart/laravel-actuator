<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

use Closure;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * Counts events and the time they took, in seconds.
 */
final readonly class Timer
{
    /**
     * @internal use MeterRegistry::timer()
     *
     * @param  array<non-empty-string, string|int|float|bool>  $tags
     */
    public function __construct(private ?HistogramInterface $histogram, private array $tags) {}

    public function record(float $seconds): void
    {
        if (is_finite($seconds)) {
            $this->histogram?->record(max(0.0, $seconds), $this->tags);
        }
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
