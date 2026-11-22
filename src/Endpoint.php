<?php

declare(strict_types=1);

namespace Nwidart\Actuator;

use InvalidArgumentException;
use Nwidart\Actuator\Http\Controllers\EnvController;
use Nwidart\Actuator\Http\Controllers\HealthController;
use Nwidart\Actuator\Http\Controllers\InfoController;
use Nwidart\Actuator\Http\Controllers\MappingsController;
use Nwidart\Actuator\Http\Controllers\ScheduledTasksController;

enum Endpoint: string
{
    case Health = 'health';
    case Info = 'info';
    case Env = 'env';
    case Mappings = 'mappings';
    case ScheduledTasks = 'scheduledtasks';

    /**
     * @return list<self>
     */
    public static function exposed(): array
    {
        $include = config()->array('actuator.exposure.include', []);
        $exclude = config()->array('actuator.exposure.exclude', []);

        return array_values(array_filter(
            self::cases(),
            static fn (self $endpoint): bool => self::matches($endpoint, $include) && ! self::matches($endpoint, $exclude),
        ));
    }

    /**
     * @return class-string
     */
    public function controller(): string
    {
        return match ($this) {
            self::Health => HealthController::class,
            self::Info => InfoController::class,
            self::Env => EnvController::class,
            self::Mappings => MappingsController::class,
            self::ScheduledTasks => ScheduledTasksController::class,
        };
    }

    /**
     * Middleware shared by every actuator route.
     *
     * @return list<string>
     */
    public static function sharedMiddleware(): array
    {
        return self::middlewareFrom('actuator.middleware');
    }

    /**
     * @return list<string>
     */
    public function middleware(): array
    {
        return [...self::sharedMiddleware(), ...self::middlewareFrom("actuator.{$this->value}.middleware")];
    }

    /**
     * @return list<string>
     */
    private static function middlewareFrom(string $key): array
    {
        $middleware = [];

        foreach (config()->array($key, []) as $name) {
            $middleware[] = is_string($name) ? $name : throw new InvalidArgumentException("Config [{$key}] must be a list of middleware names.");
        }

        return $middleware;
    }

    /**
     * @param  array<mixed>  $ids
     */
    private static function matches(self $endpoint, array $ids): bool
    {
        return in_array('*', $ids, true) || in_array($endpoint->value, $ids, true);
    }
}
