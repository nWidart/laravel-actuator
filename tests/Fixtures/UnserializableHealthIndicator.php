<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures;

use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;

/**
 * Returns a detail that no cache store can serialize.
 */
final readonly class UnserializableHealthIndicator implements HealthIndicator
{
    public function health(): Health
    {
        return Health::up(['key' => 'value', 'callback' => static fn (): int => 1]);
    }
}
