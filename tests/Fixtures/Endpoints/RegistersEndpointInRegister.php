<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures\Endpoints;

use Illuminate\Support\ServiceProvider;
use Nwidart\Actuator\Actuator;

final class RegistersEndpointInRegister extends ServiceProvider
{
    public function register(): void
    {
        Actuator::endpoint('widgets', WidgetsEndpoint::class);
    }
}
