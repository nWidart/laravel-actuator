<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;
use Nwidart\Actuator\Support\WatchedQueues;
use Throwable;

/**
 * Reports the size of each watched queue, by connection then queue. Where the driver counts them
 * (the built-in drivers since Laravel 12.20), the details also hold the pending, delayed and
 * reserved jobs. DOWN when a queue reaches the down threshold or cannot be read, WARNING when one
 * reaches the warning threshold. A queue that cannot be read shows its error and leaves the others
 * reported, except when it is the first queue of its connection: the connection is taken as down,
 * and its other queues are skipped rather than each waiting for the connect timeout.
 */
final readonly class QueueHealthIndicator implements HealthIndicator
{
    /**
     * @param  array<string, string|array<array-key, string>>|null  $queues  connection => queue names. Null reads "actuator.queue.queues"; empty watches the default queue.
     * @param  int|null  $warningThreshold  size at which a queue is WARNING. Null never is.
     * @param  int|null  $downThreshold  size at which a queue is DOWN. Null never is.
     */
    public function __construct(
        private QueueFactory $queue,
        private Repository $config,
        private ?array $queues = null,
        private ?int $warningThreshold = null,
        private ?int $downThreshold = null,
    ) {}

    public function health(): Health
    {
        $details = [];
        $sizes = [];
        $failed = false;

        foreach (WatchedQueues::resolve($this->config, $this->queues) as $connection => $names) {
            $counts = WatchedQueues::read(fn () => $this->queue->connection($connection), $names);
            // When the first queue fails, read() takes the connection as down and skips the others.
            $connectionFailed = $counts[$names[0]] instanceof Throwable;

            foreach ($counts as $name => $queue) {
                if ($queue instanceof Throwable) {
                    $details[$connection][$name] = ['error' => $connectionFailed && $name !== $names[0]
                        ? "Skipped: the connection failed on queue [{$names[0]}]"
                        : self::error($queue)];
                    $failed = true;

                    continue;
                }

                $details[$connection][$name] = ['size' => $queue['size']];
                foreach ($queue as $key => $count) {
                    if ($count instanceof Throwable) {
                        // A count by state failed: the size stays, with the error once.
                        $details[$connection][$name]['error'] ??= self::error($count);
                        $failed = true;
                    } else {
                        $details[$connection][$name][$key] = $count;
                    }
                }

                $sizes[] = $queue['size'];
            }
        }

        $largest = $sizes === [] ? 0 : max($sizes);
        $health = match (true) {
            $failed, $this->downThreshold !== null && $largest >= $this->downThreshold => Health::down(['queues' => $details]),
            $this->warningThreshold !== null && $largest >= $this->warningThreshold => Health::warning(['queues' => $details]),
            default => Health::up(['queues' => $details]),
        };

        if ($this->warningThreshold !== null) {
            $health = $health->withDetail('warningThreshold', $this->warningThreshold);
        }

        return $this->downThreshold === null ? $health : $health->withDetail('downThreshold', $this->downThreshold);
    }

    private static function error(Throwable $exception): string
    {
        return $exception::class.': '.$exception->getMessage();
    }
}
