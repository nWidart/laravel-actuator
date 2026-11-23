<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Support;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The queues actuator watches, as connection name => queue names. Null reads
 * "actuator.queue.queues". An empty map watches the default queue of the default connection.
 *
 * @internal
 */
final class WatchedQueues
{
    /**
     * @param  array<mixed>|null  $queues  connection => queue names, or one queue name. Null reads "actuator.queue.queues".
     * @return array<string, list<string>>
     *
     * @throws InvalidArgumentException when the queues are not queue names keyed by connection name
     */
    public static function resolve(Repository $config, ?array $queues = null): array
    {
        $queues ??= $config->get('actuator.queue.queues') ?? [];

        if ($queues === []) {
            $connection = $config->get('queue.default');
            $connection = is_string($connection) ? $connection : 'sync';
            $queue = $config->get("queue.connections.{$connection}.queue");

            return [$connection => [is_string($queue) && $queue !== '' ? $queue : 'default']];
        }

        $watched = [];
        foreach (is_array($queues) ? $queues : [0 => $queues] as $connection => $names) {
            $names = is_string($names) ? [$names] : $names;

            if (! is_string($connection) || ! is_array($names) || $names === [] || array_filter($names, is_string(...)) !== $names) {
                throw new InvalidArgumentException("Watched queues must map a connection name to queue names: ['connection' => ['queue', ...]].");
            }

            $watched[$connection] = array_values($names);
        }

        return $watched;
    }
}
