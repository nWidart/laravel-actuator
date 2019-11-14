<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

class HealthContributorRegistryBuilder
{
    public function build(): HealthContributorRegistry
    {
        $registry = new HealthContributorRegistry();
        $contributors = config('actuator.health.contributors');
        foreach ($contributors as $name => $contributor) {
            $registry->register($name, new $contributor);
        }

        return $registry;
    }
}
