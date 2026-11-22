<?php

declare(strict_types=1);

namespace Nwidart\Actuator;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\ServiceProvider;
use Nwidart\Actuator\Endpoints\EndpointRegistry;

final class ActuatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/actuator.php', 'actuator');

        $this->app->singletonIf(EndpointRegistry::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/actuator.php' => config_path('actuator.php'),
            ], 'actuator-config');
        }

        $this->app->make(EndpointRegistry::class)->loadRoutes();

        // Actuator endpoints keep answering in maintenance mode, so the
        // readiness probe reports OUT_OF_SERVICE instead of an HTML 503.
        // An empty base path is skipped: excepting "/*" would disable
        // maintenance mode for the whole application.
        $basePath = trim(config()->string('actuator.base_path'), '/');

        if ($basePath !== '') {
            PreventRequestsDuringMaintenance::except([$basePath, $basePath.'/*']);
        }
    }
}
