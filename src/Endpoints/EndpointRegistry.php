<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Endpoints;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Nwidart\Actuator\Http\Controllers\DiscoveryController;
use Nwidart\Actuator\Http\Controllers\EnvController;
use Nwidart\Actuator\Http\Controllers\HealthController;
use Nwidart\Actuator\Http\Controllers\InfoController;
use Nwidart\Actuator\Http\Controllers\MappingsController;
use Nwidart\Actuator\Http\Controllers\OperationController;
use Nwidart\Actuator\Http\Controllers\ScheduledTasksController;

/**
 * The built-in and custom endpoints, and their routes.
 *
 * @internal Register a custom endpoint with Nwidart\Actuator\Actuator::endpoint().
 */
final class EndpointRegistry
{
    /**
     * Ids that would collide with the discovery route name or a configuration key.
     */
    private const RESERVED = ['discovery', 'exposure', 'middleware'];

    /**
     * @var array<string, class-string>
     */
    private array $endpoints = [
        'health' => HealthController::class,
        'info' => InfoController::class,
        'env' => EnvController::class,
        'mappings' => MappingsController::class,
        'scheduledtasks' => ScheduledTasksController::class,
    ];

    private bool $routesLoaded = false;

    public function __construct(private readonly Application $app) {}

    /**
     * @param  class-string  $class
     */
    public function register(string $id, string $class): void
    {
        if (preg_match('/^[a-z][a-zA-Z0-9]*$/', $id) !== 1) {
            throw new InvalidArgumentException("Actuator endpoint id [{$id}] must start with a lowercase letter and contain only letters and digits.");
        }

        if (in_array($id, self::RESERVED, true)) {
            throw new InvalidArgumentException("Actuator endpoint id [{$id}] is reserved.");
        }

        foreach (array_keys($this->endpoints) as $registered) {
            if (strcasecmp($registered, $id) === 0) {
                throw new InvalidArgumentException("Actuator endpoint id [{$id}] is already registered as [{$registered}].");
            }
        }

        if ($this->app->isBooted()) {
            throw new LogicException("Actuator endpoint [{$id}] is registered after the application has booted. Call Actuator::endpoint() in the register() or boot() method of a service provider.");
        }

        $this->endpoints[$id] = $class;

        // Registered from a provider that boots after the actuator provider.
        if ($this->routesLoaded && isset($this->exposed()[$id])) {
            $this->routes(fn () => $this->mapEndpoint($id, $class));
        }
    }

    /**
     * The endpoints that the "exposure" configuration exposes, by id.
     *
     * @return array<string, class-string>
     */
    public function exposed(): array
    {
        $include = config()->array('actuator.exposure.include', []);
        $exclude = config()->array('actuator.exposure.exclude', []);

        return array_filter(
            $this->endpoints,
            static fn (string $id): bool => self::matches($id, $include) && ! self::matches($id, $exclude),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function loadRoutes(): void
    {
        $this->routesLoaded = true;

        $this->routes(function (): void {
            Route::get('/', DiscoveryController::class)
                ->middleware($this->middlewareFrom('actuator.middleware'))
                ->name('discovery');

            foreach ($this->exposed() as $id => $class) {
                $this->mapEndpoint($id, $class);
            }
        });
    }

    private function routes(Closure $routes): void
    {
        if (! $this->app->routesAreCached()) {
            Route::prefix(config()->string('actuator.base_path'))->name('actuator.')->group($routes);
        }
    }

    /**
     * @param  class-string  $class
     */
    private function mapEndpoint(string $id, string $class): void
    {
        $middleware = [
            ...$this->middlewareFrom('actuator.middleware'),
            ...$this->middlewareFrom("actuator.{$id}.middleware"),
        ];

        foreach (Operation::of($id, $class) as $operation) {
            $route = Route::match([$operation->verb], $operation->path(), [
                'uses' => OperationController::class,
                'operation' => "{$class}@{$operation->method}",
            ])->middleware($middleware)->name($operation->routeName());

            if ($operation->remaining !== null) {
                $route->where($operation->remaining, '.*');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function middlewareFrom(string $key): array
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
    private static function matches(string $id, array $ids): bool
    {
        return in_array('*', $ids, true) || in_array($id, $ids, true);
    }
}
