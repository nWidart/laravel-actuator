<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Health;

use Nwidart\Actuator\Health\HealthContributor;

class DummyHealthContributor implements HealthContributor
{
    public function run(): string
    {
        return 'dummy-data';
    }
}
