<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * The queues actuator watches, as connection name => queue names. An empty list falls back to
 * "actuator.queue.queues", and an empty setting to the default queue of the default connection.
 *
 * @internal
 */
final class WatchedQueues
{
    /**
     * @param  array<string, string|array<array-key, string>>|null  $queues  connection => queue names, or one queue name. Null reads "actuator.queue.queues".
     * @return array<string, list<string>>
     */
    public static function resolve(Repository $config, ?array $queues = null): array
    {
        /** @var array<string, string|array<array-key, string>> $queues */
        $queues = $queues ?? $config->get('actuator.queue.queues', []) ?? [];

        if ($queues === []) {
            $connection = $config->get('queue.default');
            $connection = is_string($connection) ? $connection : 'sync';
            $queue = $config->get("queue.connections.{$connection}.queue");

            return [$connection => [is_string($queue) && $queue !== '' ? $queue : 'default']];
        }

        return array_map(static fn (string|array $names): array => array_values((array) $names), $queues);
    }
}
