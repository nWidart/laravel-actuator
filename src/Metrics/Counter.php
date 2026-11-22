<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

use InvalidArgumentException;
use OpenTelemetry\API\Metrics\CounterInterface;

/**
 * A value that only goes up, such as a number of orders placed.
 */
final readonly class Counter
{
    /**
     * @internal use MeterRegistry::counter()
     *
     * @param  array<non-empty-string, string|int|float|bool>  $tags
     */
    public function __construct(private string $name, private ?CounterInterface $counter, private array $tags) {}

    public function increment(float $amount = 1.0): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Counter [{$this->name}] cannot be decremented.");
        }

        if (is_finite($amount)) {
            $this->counter?->add($amount, $this->tags);
        }
    }
}
