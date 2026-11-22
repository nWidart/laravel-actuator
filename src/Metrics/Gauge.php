<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

use OpenTelemetry\API\Metrics\GaugeInterface;

/**
 * A value that goes up and down, such as a queue size. The last value set wins, across every
 * process that sets it.
 */
final readonly class Gauge
{
    /**
     * @internal use MeterRegistry::gauge()
     *
     * @param  array<non-empty-string, string|int|float|bool>  $tags
     */
    public function __construct(private ?GaugeInterface $gauge, private array $tags) {}

    public function set(float $value): void
    {
        if (is_finite($value)) {
            $this->gauge?->record($value, $this->tags);
        }
    }
}
