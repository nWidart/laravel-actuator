<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

interface HealthIndicator
{
    /**
     * Report the health of one component. A thrown exception
     * reports the component as DOWN with the error message.
     */
    public function health(): Health;
}
