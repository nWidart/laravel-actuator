<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Contracts\Foundation\Application;
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;

/**
 * OUT_OF_SERVICE while the application is in maintenance mode, so load
 * balancers and Kubernetes stop routing traffic without restarting it.
 */
final readonly class ReadinessStateHealthIndicator implements HealthIndicator
{
    public function __construct(private Application $app) {}

    public function health(): Health
    {
        return $this->app->isDownForMaintenance() ? Health::outOfService() : Health::up();
    }
}
