<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Info\Contributors;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Nwidart\Actuator\Info\InfoContributor;

final readonly class AppInfoContributor implements InfoContributor
{
    public function __construct(private Application $app, private Repository $config) {}

    public function contribute(): array
    {
        return [
            'name' => $this->config->get('app.name'),
            'environment' => $this->app->environment(),
            'laravel' => $this->app->version(),
            'timezone' => $this->config->get('app.timezone'),
            'locale' => $this->app->getLocale(),
        ];
    }
}
