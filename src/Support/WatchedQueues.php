<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Support;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Queue;
use InvalidArgumentException;
use ReflectionMethod;
use Throwable;

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
     * The size and the counts by state of the queues of one connection, or why each queue could
     * not be read. Until one queue answers, a failure is taken as the connection's: its other
     * queues are skipped, with the same exception, rather than each waiting for the connect
     * timeout. So the other queues are skipped exactly when the first queue failed. A count by
     * state that fails keeps the size: each state holds the exception. With $states false, only
     * the size is read: each count by state is a query on the database driver.
     *
     * @param  Closure(): Queue  $connect  makes the connection
     * @param  list<string>  $names
     * @return array<string, array<string, int|Throwable>|Throwable> queue name => size and states, or why it could not be read
     */
    public static function read(Closure $connect, array $names, bool $states = true): array
    {
        try {
            $queue = $connect();
        } catch (Throwable $exception) {
            return array_fill_keys($names, $exception);
        }

        $counts = [];
        $reached = false;

        foreach ($names as $name) {
            try {
                $size = $queue->size($name);
                $reached = true;
            } catch (Throwable $exception) {
                if (! $reached) {
                    return array_fill_keys($names, $exception);
                }

                $counts[$name] = $exception;

                continue;
            }

            if (! $states) {
                $counts[$name] = ['size' => $size];

                continue;
            }

            try {
                $byState = self::counts($queue, $name);
            } catch (Throwable $exception) {
                $byState = array_fill_keys(array_keys(self::COUNTS), $exception);
            }

            $counts[$name] = ['size' => $size, ...$byState];
        }

        return $counts;
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
    private static function counts(object $queue, string $name): array
    {
        $counts = [];

        foreach (self::COUNTS as $state => $method) {
            // method_exists() alone accepts a protected method, and is_callable() any name of a
            // __call(), even that of a protected method: only a declared public method counts.
            if (! method_exists($queue, $method) || ! (new ReflectionMethod($queue, $method))->isPublic()) {
                continue;
            }

            $count = $queue->{$method}($name);
            if (is_numeric($count)) {
                $counts[$state] = (int) $count;
            }
        }

        return $counts;
    }
}
