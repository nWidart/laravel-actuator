<?php

namespace Nwidart\Actuator;

use Illuminate\Support\ServiceProvider;
use Nwidart\Actuator\Health\HealthBuilder;
use Nwidart\Actuator\Health\HealthContributorRegistry;
use Nwidart\Actuator\Health\HealthContributorRegistryBuilder;

class LaravelActuatorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/actuator.php' => config_path('actuator.php'),
            ], 'config');
        }
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/actuator.php', 'actuator');

        $this->app->singleton(HealthContributorRegistry::class, static function () {
            return (new HealthContributorRegistryBuilder())->build();
        });
        $this->app->singleton(HealthBuilder::class, static function ($app) {
            return new HealthBuilder($app[HealthContributorRegistry::class]);
        });
    }
}
