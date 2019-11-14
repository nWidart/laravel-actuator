<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

class StatusContributor implements HealthContributor
{
    public function run(): string
    {
        return 'UP';
    }
}
