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
 *
 * @phpstan-type GroupConfig array{include?: list<string>, show_details?: string|null, show_components?: string|null, status?: array<string, mixed>}
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
        /** @var array<string, GroupConfig> $groups */
        $groups = $this->config->get('actuator.health.groups', []);
        /** @var array<string, string|array{0: class-string, 1?: array<string, mixed>}> $indicators */
        $indicators = $this->config->get('actuator.health.indicators', []);

        $group = $this->group($path);
        if ($group !== null) {
            array_shift($path);
        }

        $members = $group === null ? array_keys($indicators) : $this->members($group['include'] ?? [], $indicators);
        $order = $this->order($group ?? []);

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

        $health = ['status' => Status::aggregate(array_column($components, 'status'), $order)];

        if ($showComponents && $components !== []) {
            $health['components'] = $components;
        }

        if ($group === null && $groups !== []) {
            $health['groups'] = array_keys($groups);
        }

        return $health;
    }

    /**
     * The HTTP status code for a status at a path. The group's "status.http_mapping" wins over
     * the endpoint's; an unmapped status keeps its default code.
     *
     * @param  list<string>  $path
     */
    public function httpStatus(array $path, Status $status): int
    {
        return $this->httpMapping($this->group($path) ?? [])[$status->value] ?? $status->httpStatus();
    }

    /**
     * The group a path starts with, or null for the endpoint itself.
     *
     * @param  list<string>  $path
     * @return GroupConfig|null
     */
    private function group(array $path): ?array
    {
        if ($path === []) {
            return null;
        }

        /** @var array<string, GroupConfig> $groups */
        $groups = $this->config->get('actuator.health.groups', []);

        return $groups[$path[0]] ?? null;
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
     * The severity order, most severe first. A group's "status.order" wins over the endpoint's.
     *
     * @param  array{status?: array<string, mixed>}  $group
     * @return list<Status>
     */
    private function order(array $group): array
    {
        $order = $this->statusSetting($group, 'order') ?? [];

        if (! is_array($order) || ! array_is_list($order)) {
            throw new InvalidArgumentException('Health status order must be a list of status codes.');
        }

        return array_map(fn (mixed $code): Status => $this->status($code, 'order'), $order);
    }

    /**
     * @param  array{status?: array<string, mixed>}  $group
     * @return array<string, int>
     */
    private function httpMapping(array $group): array
    {
        $mapping = $this->statusSetting($group, 'http_mapping') ?? [];

        if (! is_array($mapping)) {
            throw new InvalidArgumentException('Health status HTTP mapping must map status codes to HTTP status codes.');
        }

        foreach ($mapping as $code => $httpStatus) {
            $this->status($code, 'HTTP mapping');

            if (! is_int($httpStatus)) {
                throw new InvalidArgumentException("Health status HTTP mapping for [{$code}] must be an integer HTTP status code.");
            }
        }

        /** @var array<string, int> $mapping */
        return $mapping;
    }

    /**
     * A group's "status" setting wins over the endpoint's "status" setting. Null when neither is set.
     *
     * @param  array{status?: array<string, mixed>}  $group
     */
    private function statusSetting(array $group, string $key): mixed
    {
        return $group['status'][$key] ?? $this->config->get("actuator.health.status.{$key}");
    }

    private function status(mixed $code, string $setting): Status
    {
        $status = is_string($code) ? Status::tryFrom($code) : null;

        return $status ?? throw new InvalidArgumentException(
            "Health status {$setting} contains unknown status [".(is_scalar($code) ? $code : get_debug_type($code)).'].'
        );
    }

    /**
     * @param  string|array{0: class-string, 1?: array<string, mixed>}  $definition
     * @return array{status: Status, details?: array<string, mixed>}
     */
    private function evaluate(string $name, string|array $definition, bool $showDetails): array
    {
        $health = $this->check($name, $definition);

        $result = ['status' => $health->status];

        if ($showDetails && $health->details !== []) {
            $result['details'] = $health->details;
        }

        return $result;
    }

    /**
     * Resolve and run the indicator. A class that cannot be resolved, or an indicator that throws,
     * reports DOWN with the exception, so one broken indicator does not fail the whole endpoint.
     *
     * @param  string|array{0: class-string, 1?: array<string, mixed>}  $definition
     */
    private function check(string $name, string|array $definition): Health
    {
        try {
            $indicator = $this->container->make(...(array) $definition);
        } catch (Throwable $exception) {
            Log::warning("Health indicator [{$name}] could not be resolved: {$exception->getMessage()}", ['exception' => $exception]);

            return Health::down()->withException($exception);
        }

        if (! $indicator instanceof HealthIndicator) {
            throw new InvalidArgumentException("Health indicator [{$name}] must implement ".HealthIndicator::class.'.');
        }

        try {
            return $indicator->health();
        } catch (Throwable $exception) {
            Log::warning("Health indicator [{$name}] failed: {$exception->getMessage()}", ['exception' => $exception]);

            return Health::down()->withException($exception);
        }
    }
}
