<?php

declare(strict_types=1);

namespace Nwidart\Actuator;

use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use Nwidart\Actuator\Endpoints\EndpointRegistry;
use Nwidart\Actuator\Support\ScheduledTaskTags;

final class Actuator
{
    /**
     * Register a custom endpoint under the given id.
     *
     * Call this from a service provider, preferably in register(). The
     * endpoint is exposed only when the "exposure" configuration allows its id.
     *
     * @param  class-string  $class  A class with methods marked as read, write or delete operations.
     */
    public static function endpoint(string $id, string $class): void
    {
        $container = Container::getInstance();

        // The caller's provider can run before the actuator provider binds the registry.
        $container->singletonIf(EndpointRegistry::class);
        $container->make(EndpointRegistry::class)->register($id, $class);
    }

    /**
     * Add tags to the entries of the scheduledtasks endpoint.
     *
     * The callback receives each scheduled event and returns an array of tags, such as
     * ['module' => 'Blog']. A task without tags has no "tags" key.
     *
     * @param  Closure(Event): array<mixed>  $callback
     */
    public static function tagScheduledTasksUsing(Closure $callback): void
    {
        $container = Container::getInstance();

        $container->singletonIf(ScheduledTaskTags::class);
        $container->make(ScheduledTaskTags::class)->add($callback);
    }
}
