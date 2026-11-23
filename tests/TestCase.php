<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests;

use Nwidart\Actuator\ActuatorServiceProvider;
use Nwidart\Actuator\Health\HealthEndpoint;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // HealthEndpoint keeps the health cache warnings it logged in a static: start each test with none.
        (new ReflectionProperty(HealthEndpoint::class, 'logged'))->setValue(null, []);
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
