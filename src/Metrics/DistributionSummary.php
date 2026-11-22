<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

/**
 * Counts events and the sum of an amount that is not a duration, such as a payload size.
 */
final readonly class DistributionSummary
{
    /**
     * @internal use MeterRegistry::summary()
     *
     * @param  list<float>  $buckets
     */
    public function __construct(private ?Buffer $buffer, private MeterId $id, private array $buckets) {}

    public function record(float $amount): void
    {
        $this->buffer?->observe($this->id, $amount, $this->buckets);
    }
}
