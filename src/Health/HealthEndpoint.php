<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Actuator\Visibility;
use Throwable;

/**
 * Evaluates health indicators for the whole application, a group, or a single component.
 *
 * With "health.cache.ttl" above 0, each indicator's result is kept in a cache store local to the
 * instance for that many seconds, keyed by indicator name and definition, so the endpoint, its
 * groups and its components share one run.
 *
 * @phpstan-type GroupConfig array{include?: list<string>, show_details?: string|null, show_components?: string|null, status?: array<string, mixed>}
 */
final class HealthEndpoint
{
    /** The histogram of how long each indicator takes to run, in seconds. */
    public const DURATION_METER = 'laravel.health.indicator.duration';

    /**
     * The cache problems already logged, so that a failing store does not log a warning for each
     * indicator of each probe. Static, so once per process: per request under PHP-FPM, per worker
     * under Octane. A store that fails again after it recovered is not logged again.
     *
     * @var array<string, true>
     */
    private static array $logged = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly CacheFactory $cache,
        private readonly MeterRegistry $registry,
    ) {}

    /**
     * Resolve the health for a path: [], [component], [group] or [group, component].
     * Returns null when the path does not exist or its components are hidden. Pass $cache false to
     * run every indicator now, ignoring and leaving untouched the cached results.
     *
     * @param  list<string>  $path
     * @return array{status: Status, details?: array<string, mixed>, components?: array<string, mixed>, groups?: list<string>}|null
     */
    public function health(array $path = [], bool $cache = true): ?array
    {
        /** @var array<string, GroupConfig> $groups */
        $groups = $this->config->get('actuator.health.groups', []);
        /** @var array<string, string|array{0: class-string, 1?: array<string, mixed>}> $indicators */
        $indicators = $this->config->get('actuator.health.indicators', []);

        $groupName = $this->groupName($path);
        $group = $groupName === null ? null : $groups[$groupName];
        if ($group !== null) {
            array_shift($path);
        }

        $members = $group === null ? array_keys($indicators) : $this->members($group['include'] ?? [], $indicators);
        $order = $this->order($groupName);

        $showDetails = $this->visibility($group ?? [], 'show_details')?->isGranted() ?? false;
        $showComponents = $this->visibility($group ?? [], 'show_components')?->isGranted() ?? $showDetails;
        $ttl = $cache ? $this->ttl() : 0;

        if ($path !== []) {
            if (! $showComponents || count($path) > 1 || ! in_array($path[0], $members, true)) {
                return null;
            }

            return $this->evaluate($path[0], $indicators[$path[0]], $showDetails, $ttl);
        }

        $components = [];
        foreach ($members as $name) {
            $components[$name] = $this->evaluate($name, $indicators[$name], $showDetails, $ttl);
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
        return $this->httpMapping($this->groupName($path))[$status->value] ?? $status->httpStatus();
    }

    /**
     * The name of the group a path starts with, or null for the endpoint itself.
     *
     * @param  list<string>  $path
     */
    private function groupName(array $path): ?string
    {
        /** @var array<string, GroupConfig> $groups */
        $groups = $this->config->get('actuator.health.groups', []);

        return $path !== [] && isset($groups[$path[0]]) ? $path[0] : null;
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
     * The severity order, most severe first.
     *
     * @return list<Status>
     */
    private function order(?string $groupName): array
    {
        [$key, $order] = $this->statusSetting($groupName, 'order');
        $order ??= [];

        if (! is_array($order) || ! array_is_list($order)) {
            throw new InvalidArgumentException("Health status setting [{$key}] must be a list of status codes.");
        }

        $statuses = [];
        foreach ($order as $code) {
            $status = $this->status($code, $key);

            if (in_array($status, $statuses, true)) {
                throw new InvalidArgumentException("Health status setting [{$key}] lists [{$status->value}] more than once.");
            }

            $statuses[] = $status;
        }

        return $statuses;
    }

    /**
     * The HTTP status code of each mapped status, keyed by status code.
     *
     * @return array<string, int>
     */
    private function httpMapping(?string $groupName): array
    {
        [$key, $mapping] = $this->statusSetting($groupName, 'http_mapping');
        $mapping ??= [];

        if (! is_array($mapping)) {
            throw new InvalidArgumentException("Health status setting [{$key}] must map status codes to HTTP status codes.");
        }

        $codes = [];
        foreach ($mapping as $code => $httpStatus) {
            $status = $this->status($code, $key);

            if (isset($codes[$status->value])) {
                throw new InvalidArgumentException("Health status setting [{$key}] maps [{$status->value}] more than once.");
            }

            if (! is_int($httpStatus) || $httpStatus < 100 || $httpStatus > 599) {
                throw new InvalidArgumentException("Health status setting [{$key}] must map [{$status->value}] to an integer HTTP status code from 100 to 599.");
            }

            $codes[$status->value] = $httpStatus;
        }

        return $codes;
    }

    /**
     * A key of the group's "status" setting wins over the same key of the endpoint's "status" setting.
     * Returns the config key the value comes from, for error messages, and the value, null when unset.
     *
     * @return array{0: string, 1: mixed}
     */
    private function statusSetting(?string $groupName, string $key): array
    {
        /** @var array<string, GroupConfig> $groups */
        $groups = $this->config->get('actuator.health.groups', []);
        $value = $groupName === null ? null : $groups[$groupName]['status'][$key] ?? null;

        return $value !== null
            ? ["actuator.health.groups.{$groupName}.status.{$key}", $value]
            : ["actuator.health.status.{$key}", $this->config->get("actuator.health.status.{$key}")];
    }

    /**
     * A status code, in any case.
     */
    private function status(mixed $code, string $key): Status
    {
        $status = is_string($code) ? Status::tryFrom(strtoupper($code)) : null;

        return $status ?? throw new InvalidArgumentException(
            "Health status setting [{$key}] contains unknown status [".(is_scalar($code) ? $code : get_debug_type($code)).'].'
        );
    }

    /**
     * @param  string|array{0: class-string, 1?: array<string, mixed>}  $definition
     * @return array{status: Status, details?: array<string, mixed>}
     */
    private function evaluate(string $name, string|array $definition, bool $showDetails, int $ttl): array
    {
        [$health, $seconds] = $ttl > 0 ? $this->cached($name, $definition, $ttl) : $this->run($name, $definition);

        $result = ['status' => $health->status];

        if ($showDetails) {
            // A "duration" detail of the indicator wins.
            $result['details'] = $health->details + ['duration' => self::duration($seconds)];
        }

        return $result;
    }

    /**
     * The cached result of the indicator, or a new run stored for $ttl seconds. A cache that fails
     * is logged and skipped: health never depends on the cache.
     *
     * @param  string|array{0: class-string, 1?: array<string, mixed>}  $definition
     * @return array{0: Health, 1: float}
     */
    private function cached(string $name, string|array $definition, int $ttl): array
    {
        // An empty name would select the default store, often shared between instances.
        $storeName = $this->config->get('actuator.health.cache.store');
        $storeName = $storeName === null || $storeName === '' ? 'file' : $storeName;

        try {
            $store = $this->cache->store(is_string($storeName) ? $storeName : throw new InvalidArgumentException('Health setting [actuator.health.cache.store] must be a cache store name.'));
            // The definition is part of the key, so a changed class or argument is a new entry.
            $key = "actuator:health:{$name}:".hash('xxh128', serialize($definition));
            $entry = $store->get($key);
        } catch (Throwable $exception) {
            $this->warnOnce('store', "Health cache could not be read for indicator [{$name}]: {$exception->getMessage()}", $exception);

            return $this->run($name, $definition);
        }

        $hit = self::fromCache($entry);
        if ($hit !== null) {
            return $hit;
        }

        [$health, $seconds] = $this->run($name, $definition);

        try {
            // Only plain values, never the indicator or the exception it threw.
            $store->put($key, ['status' => $health->status->value, 'details' => $health->details, 'duration' => $seconds], $ttl);
        } catch (Throwable $exception) {
            $this->warnOnce('store', "Health cache could not be written for indicator [{$name}]: {$exception->getMessage()}", $exception);
        }

        return [$health, $seconds];
    }

    /**
     * Rebuilds a result from a cache entry. Null for a miss, or an entry in another shape.
     *
     * @return array{0: Health, 1: float}|null
     */
    private static function fromCache(mixed $entry): ?array
    {
        if (! is_array($entry) || ! is_string($entry['status'] ?? null) || ! is_array($entry['details'] ?? null) || ! is_float($entry['duration'] ?? null)) {
            return null;
        }

        $status = Status::tryFrom($entry['status']);
        $details = [];
        foreach ($entry['details'] as $key => $value) {
            $details[(string) $key] = $value;
        }

        return $status === null ? null : [new Health($status, $details), $entry['duration']];
    }

    /**
     * Runs the indicator, records how long it took in the histogram, and returns both.
     *
     * @param  string|array{0: class-string, 1?: array<string, mixed>}  $definition
     * @return array{0: Health, 1: float}
     */
    private function run(string $name, string|array $definition): array
    {
        $start = hrtime(true);
        $health = $this->check($name, $definition);
        $seconds = (hrtime(true) - $start) / 1e9;

        $this->registry->timer(self::DURATION_METER, ['indicator' => $name])->record($seconds);

        return [$health, $seconds];
    }

    /**
     * Seconds the results stay cached. 0, the default, turns the cache off. An invalid value logs a
     * warning and turns the cache off too: a performance setting must never fail the probes.
     */
    private function ttl(): int
    {
        $ttl = $this->config->get('actuator.health.cache.ttl', 0);

        if ($ttl === null || $ttl === '') {
            return 0;
        }

        if (! is_numeric($ttl) || (int) $ttl != $ttl || $ttl < 0) {
            $this->warnOnce('ttl', 'Health setting [actuator.health.cache.ttl] must be a whole number of seconds, 0 or more. The health cache is off.');

            return 0;
        }

        return (int) $ttl;
    }

    private function warnOnce(string $key, string $message, ?Throwable $exception = null): void
    {
        if (isset(self::$logged[$key])) {
            return;
        }

        self::$logged[$key] = true;
        Log::warning($message, $exception === null ? [] : ['exception' => $exception]);
    }

    /**
     * An ISO-8601 duration with millisecond precision, as Spring Boot writes a Duration: PT0.012S.
     */
    private static function duration(float $seconds): string
    {
        return 'PT'.round($seconds, 3).'S';
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
