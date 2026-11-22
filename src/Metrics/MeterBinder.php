<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

/**
 * Registers a group of meters, as Micrometer's MeterBinder does.
 *
 * @internal
 */
interface MeterBinder
{
    public function bind(MeterRegistry $registry): void;
}
