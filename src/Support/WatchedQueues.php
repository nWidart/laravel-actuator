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
    /** State => the method of the queue that counts its jobs, when the driver has it. */
    private const COUNTS = [
        'pending' => 'pendingSize',
        'delayed' => 'delayedSize',
        'reserved' => 'reservedSize',
    ];

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

    /**
     * The pending, delayed and reserved jobs of a queue, for the states the driver counts: the
     * built-in drivers since Laravel 12.20. A state is left out when the queue declares no public
     * method for it, as on Laravel 12.0 to 12.19 and on some third-party drivers, or when that
     * method returns no number. Typed object, not Queue: the Queue contract declares the methods
     * only since Laravel 12.20.
     *
     * @return array<'pending'|'delayed'|'reserved', int>
     */
    public static function counts(object $queue, string $name): array
    {
        $counts = [];

        foreach (self::COUNTS as $state => $method) {
            // method_exists() alone accepts a protected method, is_callable() alone any name of a __call().
            $callable = [$queue, $method];
            if (! method_exists($queue, $method) || ! is_callable($callable)) {
                continue;
            }

            $count = $callable($name);
            if (is_numeric($count)) {
                $counts[$state] = (int) $count;
            }
        }

        return $counts;
    }
}
