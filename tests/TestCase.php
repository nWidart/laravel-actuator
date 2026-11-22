<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests;

use Nwidart\Actuator\ActuatorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ActuatorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        // nwidart/laravel-metrics keeps the metrics in the array cache: one test, one store, no Redis.
        $app['config']->set('metrics.store.driver', 'cache');
        $app['config']->set('metrics.store.cache_store', 'array');
    }
}
