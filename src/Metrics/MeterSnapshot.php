<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

/**
 * The state of one meter when it was read. It does not depend on any
 * monitoring system: an exposition format turns it into its own names.
 */
final readonly class MeterSnapshot
{
    /**
     * @param  string  $name  dotted name, e.g. "http.server.requests"
     * @param  string  $unit  base unit, e.g. "seconds" or "bytes". Empty when there is none.
     * @param  array<string, string>  $tags
     * @param  float  $value  the total of a counter or the value of a gauge
     * @param  float  $count  the number of observations of a timer or summary
     * @param  float  $sum  the sum of the observations of a timer or summary
     * @param  list<array{float, float}>  $buckets  [upper bound, cumulative count], ascending
     */
    public function __construct(
        public string $name,
        public MeterType $type,
        public string $unit,
        public array $tags,
        public string $description = '',
        public float $value = 0.0,
        public float $count = 0.0,
        public float $sum = 0.0,
        public array $buckets = [],
    ) {}
}
