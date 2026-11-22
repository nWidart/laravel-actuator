<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Nwidart\Actuator\Visibility;
use Throwable;

/**
 * Evaluates health indicators for the whole application, a group, or a single component.
 */
final readonly class HealthEndpoint
{
    public function __construct(private Container $container, private Repository $config) {}

    /**
     * Resolve the health for a path: [], [component], [group] or [group, component].
     * Returns null when the path does not exist or its components are hidden.
     *
     * @param  list<string>  $path
     * @return array{status: Status, details?: array<string, mixed>, components?: array<string, mixed>, groups?: list<string>}|null
     */
    public function health(array $path = []): ?array
    {
        /** @var array<string, array{include?: list<string>, show_details?: string|null, show_components?: string|null}> $groups */
        $groups = $this->config->get('actuator.health.groups', []);
        /** @var array<string, string|array{0: class-string, 1?: array<string, mixed>}> $indicators */
        $indicators = $this->config->get('actuator.health.indicators', []);

        $group = $path !== [] && isset($groups[$path[0]]) ? $groups[array_shift($path)] : null;
        $members = $group === null ? array_keys($indicators) : $this->members($group['include'] ?? [], $indicators);

        $showDetails = $this->visibility($group ?? [], 'show_details')?->isGranted() ?? false;
        $showComponents = $this->visibility($group ?? [], 'show_components')?->isGranted() ?? $showDetails;

        if ($path !== []) {
            if (! $showComponents || count($path) > 1 || ! in_array($path[0], $members, true)) {
                return null;
            }

            return $this->evaluate($path[0], $indicators[$path[0]], $showDetails);
        }

        $components = [];
        foreach ($members as $name) {
            $components[$name] = $this->evaluate($name, $indicators[$name], $showDetails);
        }

        $health = ['status' => Status::aggregate(array_column($components, 'status'))];

        if ($showComponents && $components !== []) {
            $health['components'] = $components;
        }

        if ($group === null && $groups !== []) {
            $health['groups'] = array_keys($groups);
        }

        return $health;
    }

    /**
     * @param  list<string>  $include
     * @param  array<string, mixed>  $indicators
     * @return list<string>
     */
    private function members(array $include, array $indicators): array
    {
        foreach ($include as $name) {
            if (! array_key_exists($name, $indicators)) {
                throw new InvalidArgumentException("Health group includes unknown indicator [{$name}].");
            }
        }

        return $include;
    }

    /**
     * A group setting wins over the endpoint setting. Null when neither is set.
     *
     * @param  array<string, mixed>  $group
     */
    private function visibility(array $group, string $key): ?Visibility
    {
        $value = $group[$key] ?? $this->config->get("actuator.health.{$key}");

        return $value === null ? null : Visibility::fromConfig($value);
    }

    /**
     * @param  string|array{0: class-string, 1?: array<string, mixed>}  $definition
     * @return array{status: Status, details?: array<string, mixed>}
     */
    private function evaluate(string $name, string|array $definition, bool $showDetails): array
    {
        $indicator = $this->container->make(...(array) $definition);

        if (! $indicator instanceof HealthIndicator) {
            throw new InvalidArgumentException("Health indicator [{$name}] must implement ".HealthIndicator::class.'.');
        }

        try {
            $health = $indicator->health();
        } catch (Throwable $exception) {
            Log::warning("Health indicator [{$name}] failed: {$exception->getMessage()}", ['exception' => $exception]);
            $health = Health::down()->withException($exception);
        }

        $result = ['status' => $health->status];

        if ($showDetails && $health->details !== []) {
            $result['details'] = $health->details;
        }

        return $result;
    }
}
