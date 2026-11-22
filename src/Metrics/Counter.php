<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

use InvalidArgumentException;

/**
 * A value that only goes up, such as a number of orders placed.
 */
final readonly class Counter
{
    /**
     * @internal use MeterRegistry::counter()
     */
    public function __construct(private ?Buffer $buffer, private MeterId $id) {}

    public function increment(float $amount = 1.0): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Counter [{$this->id->name}] cannot be decremented.");
        }

        $this->buffer?->increment($this->id->series('total'), $amount);
    }
}
