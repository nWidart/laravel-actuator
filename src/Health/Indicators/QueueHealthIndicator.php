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
 * reported.
 */
final readonly class QueueHealthIndicator implements HealthIndicator
{
    /** Detail key => the method of the queue that counts it, when the driver has it. */
    private const COUNTS = [
        'pending' => 'pendingSize',
        'delayed' => 'delayedSize',
        'reserved' => 'reservedSize',
    ];

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
            foreach ($names as $name) {
                try {
                    $details[$connection][$name] = $this->counts($connection, $name);
                    $sizes[] = $details[$connection][$name]['size'];
                } catch (Throwable $exception) {
                    $details[$connection][$name] = ['error' => $exception::class.': '.$exception->getMessage()];
                    $failed = true;
                }
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

    /**
     * @return array<string, int>
     */
    private function counts(string $connection, string $name): array
    {
        $queue = $this->queue->connection($connection);
        $counts = ['size' => $queue->size($name)];

        foreach (self::COUNTS as $key => $method) {
            $count = self::count($queue, $method, $name);
            if ($count !== null) {
                $counts[$key] = $count;
            }
        }

        return $counts;
    }

    /**
     * What the queue's $method counts. Null when the queue declares no public $method, as on Laravel 12.0
     * to 12.19 and on some third-party drivers, or when it returns no number. Typed object, not
     * Queue: the Queue contract declares the method only since Laravel 12.20.
     */
    private static function count(object $queue, string $method, string $name): ?int
    {
        // method_exists() alone accepts a protected method, is_callable() alone any name of a __call().
        $callable = [$queue, $method];
        if (! method_exists($queue, $method) || ! is_callable($callable)) {
            return null;
        }

        $count = $callable($name);

        return is_numeric($count) ? (int) $count : null;
    }
}
