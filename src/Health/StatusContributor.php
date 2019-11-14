<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

use Illuminate\Contracts\Foundation\Application;

class StatusContributor implements HealthContributor
{
    /**
     * @var Application
     */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function run(): string
    {
        return $this->app->isDownForMaintenance() ? 'DOWN' : 'UP';
    }
}
