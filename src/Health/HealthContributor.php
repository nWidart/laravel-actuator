<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

interface HealthContributor
{
    public function run();
}
