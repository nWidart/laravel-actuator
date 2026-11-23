<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests;

use Nwidart\Actuator\ActuatorServiceProvider;
use Nwidart\Actuator\Health\HealthEndpoint;
use Nwidart\Actuator\Support\SchedulerStore;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // HealthEndpoint and SchedulerStore keep the warnings they logged in a static: start each test with none.
        (new ReflectionProperty(HealthEndpoint::class, 'logged'))->setValue(null, []);
        (new ReflectionProperty(SchedulerStore::class, 'logged'))->setValue(null, []);
    }

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
