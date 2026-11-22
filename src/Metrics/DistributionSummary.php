<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * Counts events and the sum of an amount that is not a duration, such as a payload size.
 */
final readonly class DistributionSummary
{
    /**
     * @internal use MeterRegistry::summary()
     *
     * @param  array<non-empty-string, string|int|float|bool>  $tags
     */
    public function __construct(private ?HistogramInterface $histogram, private array $tags) {}

    public function record(float $amount): void
    {
        if (is_finite($amount)) {
            $this->histogram?->record($amount, $this->tags);
        }
    }
}
