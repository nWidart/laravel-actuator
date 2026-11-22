<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;

/**
 * Always UP while the application can serve requests.
 */
final class PingHealthIndicator implements HealthIndicator
{
    public function health(): Health
    {
        return Health::up();
    }
}
