<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures\Endpoints;

use Illuminate\Support\ServiceProvider;
use Nwidart\Actuator\Actuator;

final class RegistersEndpointInBoot extends ServiceProvider
{
    public function boot(): void
    {
        Actuator::endpoint('widgets', WidgetsEndpoint::class);
    }
}
