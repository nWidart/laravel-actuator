<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

/**
 * A value that goes up and down, such as a queue size. The last value set wins.
 */
final readonly class Gauge
{
    /**
     * @internal use MeterRegistry::gauge()
     */
    public function __construct(private ?Buffer $buffer, private MeterId $id) {}

    public function set(float $value): void
    {
        $this->buffer?->set($this->id->series('value'), $value);
    }
}
